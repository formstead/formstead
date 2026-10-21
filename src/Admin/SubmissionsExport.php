<?php
/**
 * CSV export of one form's submissions.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

defined( 'ABSPATH' ) || exit;

use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Security\Capabilities;
use Formstead\Submissions\CsvColumns;
use Formstead\Submissions\CsvRow;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;

/**
 * Streams a CSV file for people who can export submissions.
 *
 * The file contains one form. Filters follow the inbox view that requested it.
 */
final class SubmissionsExport {

	/**
	 * Rows written between flushes.
	 */
	private const BATCH = 500;

	/**
	 * Hook the download action.
	 */
	public static function register(): void {
		add_action( 'admin_post_formstead_export', array( self::class, 'handle' ) );
	}

	/**
	 * Send the CSV, or refuse the request.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::EXPORT_SUBMISSIONS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to export submissions.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'formstead_export' );

		$form = ( new FormRepository() )->find_by_id( self::query_int( 'form_id' ) );
		if ( ! $form instanceof Form ) {
			wp_die(
				esc_html__( 'This form could not be found.', 'formstead' ),
				'',
				array( 'response' => 404 )
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $form ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not a filesystem write.
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die(
				esc_html__( 'The export could not be started.', 'formstead' ),
				'',
				array( 'response' => 500 )
			);
		}

		self::write( $form, self::query(), $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download, not a filesystem write.
		fclose( $output );
		exit;
	}

	/**
	 * Write one form's CSV to an open handle.
	 *
	 * @param Form            $form   Form to export. The query is limited to this form.
	 * @param SubmissionQuery $query  Status and date filters.
	 * @param resource        $output Binary write handle.
	 */
	public static function write( Form $form, SubmissionQuery $query, $output ): void {
		$query  = SubmissionQuery::from_values(
			$form->id(),
			$query->view(),
			$query->date_from() ?? '',
			$query->date_to() ?? ''
		);
		$fields = CsvColumns::fields( $form );
		$header = array();
		foreach ( array_merge( CsvColumns::leading(), $fields ) as $name ) {
			$header[] = CsvRow::guard( $name );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming a download, not a filesystem write.
		fwrite( $output, "\xEF\xBB\xBF" );
		self::put( $output, $header );

		$repository  = new SubmissionRepository();
		$after_id    = 0;
		$batch_count = 0;
		do {
			$batch       = $repository->iterate_for_export( $query, $after_id, self::BATCH );
			$batch_count = count( $batch );
			foreach ( $batch as $submission ) {
				$after_id = $submission->id();
				$payload  = $submission->payload();
				$row      = array(
					CsvRow::guard( $submission->receipt_id() ),
					CsvRow::guard( $submission->created_at() ),
					CsvRow::guard( $submission->status() ),
				);
				foreach ( $fields as $name ) {
					$row[] = CsvRow::field( $payload[ $name ] ?? null );
				}
				self::put( $output, $row );
			}
			fflush( $output );
			flush();
		} while ( self::BATCH === $batch_count );
	}

	/**
	 * URL of the export for the current filters.
	 *
	 * @param SubmissionQuery $query Inbox filters. A form id is required.
	 */
	public static function url( SubmissionQuery $query ): string {
		$args = array(
			'action'     => 'formstead_export',
			'form_id'    => (int) $query->form_id(),
			'inbox_view' => $query->view(),
		);
		if ( null !== $query->date_from() ) {
			$args['date_from'] = $query->date_from();
		}
		if ( null !== $query->date_to() ) {
			$args['date_to'] = $query->date_to();
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'formstead_export' );
	}

	/**
	 * Inbox filters from the export request.
	 */
	private static function query(): SubmissionQuery {
		return SubmissionQuery::from_values(
			self::query_int( 'form_id' ),
			self::query_text( 'inbox_view' ),
			self::query_text( 'date_from' ),
			self::query_text( 'date_to' )
		);
	}

	/**
	 * Download name. The public id is not a secret, and it avoids the internal id.
	 *
	 * @param Form $form Form being exported.
	 */
	private static function filename( Form $form ): string {
		$public_id = preg_replace( '/[^a-z0-9_]/', '', $form->public_id() );
		$public_id = is_string( $public_id ) && '' !== $public_id ? $public_id : 'form';

		return 'formstead-' . $public_id . '-' . gmdate( 'Ymd' ) . '.csv';
	}

	/**
	 * Write one CSV record.
	 *
	 * The escape argument is empty so a backslash is not added in front of quotes.
	 *
	 * @param resource $output Binary write handle.
	 * @param string[] $fields Cell values.
	 */
	private static function put( $output, array $fields ): void {
		fputcsv( $output, $fields, ',', '"', '' );
	}

	/**
	 * Integer from the export query string.
	 *
	 * @param string $key Query key.
	 */
	private static function query_int( string $key ): int {
		return absint( self::query_text( $key ) );
	}

	/**
	 * Text from the export query string.
	 *
	 * @param string $key Query key.
	 */
	private static function query_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the caller checks the export nonce.
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field cleans the export query.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}
}
