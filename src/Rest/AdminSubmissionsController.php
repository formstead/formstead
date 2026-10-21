<?php
/**
 * Authenticated submissions API.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Audit\AuditEvent;
use Formstead\Audit\AuditLog;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Forms\PublicId;
use Formstead\Security\Capabilities;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionDeleter;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Lists, reads, updates, and deletes submissions by receipt id.
 *
 * Routes are public to WordPress so a missing REST nonce does not block an
 * application password. {@see AdminAccess} performs the real check. A read
 * does not mark a submission as read.
 */
final class AdminSubmissionsController {

	/**
	 * Default page size.
	 */
	private const PER_PAGE = 20;

	/**
	 * Largest page the API will return.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Hook route registration.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the collection and the single-submission routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'formstead/v1',
			'/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'formstead/v1',
			'/submissions/(?P<receipt_id>[^/]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'show' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'update' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'destroy' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * List submissions.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$denied = AdminAccess::denied( Capabilities::READ_SUBMISSIONS );
		if ( $denied instanceof WP_REST_Response ) {
			return $denied;
		}

		$form_id = self::form_filter( $request );
		if ( $form_id instanceof WP_REST_Response ) {
			return $form_id;
		}

		$status = self::text( $request, 'status' );
		if ( '' !== $status && ! SubmissionStatus::is_known( $status ) ) {
			return AdminAccess::error( 'invalid_status', 400 );
		}

		$after  = self::text( $request, 'after' );
		$before = self::text( $request, 'before' );
		if ( self::bad_date( $after ) || self::bad_date( $before ) ) {
			return AdminAccess::error( 'invalid_date', 400 );
		}

		$email = self::text( $request, 'email' );
		$query = SubmissionQuery::from_values( $form_id, SubmissionQuery::VIEW_ALL, $after, $before, $email, $status );
		if ( '' !== trim( $email ) && '' === $query->search() ) {
			return AdminAccess::error( 'storage_failed', 500 );
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page < 1 ) {
			$per_page = self::PER_PAGE;
		}
		$per_page = min( self::MAX_PER_PAGE, $per_page );

		$list  = ( new SubmissionRepository() )->paginate( $query, $page, $per_page );
		$forms = array();
		$items = array();
		foreach ( $list->submissions() as $submission ) {
			$items[] = SubmissionResource::from_submission( $submission, self::form_for( $forms, $submission ) );
		}

		$pages    = (int) ceil( $list->total() / $per_page );
		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $list->total() );
		$response->header( 'X-WP-TotalPages', (string) max( 0, $pages ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Read one submission without changing its status.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function show( WP_REST_Request $request ): WP_REST_Response {
		$denied = AdminAccess::denied( Capabilities::READ_SUBMISSIONS );
		if ( $denied instanceof WP_REST_Response ) {
			return $denied;
		}

		$submission = self::submission( $request );
		if ( $submission instanceof WP_REST_Response ) {
			return $submission;
		}

		$form = ( new FormRepository() )->find_by_id( $submission->form_id() );

		return new WP_REST_Response( SubmissionResource::from_submission( $submission, $form ), 200 );
	}

	/**
	 * Change the inbox status.
	 *
	 * Uses the same capability as changing a status in the admin screen.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function update( WP_REST_Request $request ): WP_REST_Response {
		$denied = AdminAccess::denied( Capabilities::READ_SUBMISSIONS );
		if ( $denied instanceof WP_REST_Response ) {
			return $denied;
		}

		$submission = self::submission( $request );
		if ( $submission instanceof WP_REST_Response ) {
			return $submission;
		}

		$status = $request->get_param( 'status' );
		if ( ! is_string( $status ) || ! SubmissionStatus::is_known( $status ) ) {
			return AdminAccess::error( 'invalid_status', 400 );
		}

		( new SubmissionRepository() )->set_status( array( $submission->id() ), $status );
		$updated = ( new SubmissionRepository() )->find( $submission->id() );
		if ( ! $updated instanceof Submission ) {
			return AdminAccess::error( 'submission_not_found', 404 );
		}

		$form = ( new FormRepository() )->find_by_id( $updated->form_id() );

		return new WP_REST_Response( SubmissionResource::from_submission( $updated, $form ), 200 );
	}

	/**
	 * Delete one submission and cancel its open deliveries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function destroy( WP_REST_Request $request ): WP_REST_Response {
		$denied = AdminAccess::denied( Capabilities::DELETE_SUBMISSIONS );
		if ( $denied instanceof WP_REST_Response ) {
			return $denied;
		}

		$submission = self::submission( $request );
		if ( $submission instanceof WP_REST_Response ) {
			return $submission;
		}

		$deleted = ( new SubmissionDeleter() )->delete( array( $submission->id() ) );
		if ( ! is_int( $deleted ) ) {
			return AdminAccess::error( 'storage_failed', 500, __( 'The submissions could not be deleted.', 'formstead' ) );
		}

		AuditLog::record(
			AuditEvent::SUBMISSIONS_DELETED,
			'submission',
			$submission->id(),
			array(
				'count'  => $deleted,
				'source' => 'api',
			)
		);

		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * Form id from the `form` query argument.
	 *
	 * An empty argument means every form. An unknown public id is an error,
	 * so a typo does not look like an empty inbox.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int|WP_REST_Response Zero means every form.
	 */
	private static function form_filter( WP_REST_Request $request ): int|WP_REST_Response {
		$public_id = self::text( $request, 'form' );
		if ( '' === $public_id ) {
			return 0;
		}

		if ( ! PublicId::is_valid( $public_id ) ) {
			return AdminAccess::error( 'form_not_found', 404 );
		}

		$form = ( new FormRepository() )->find_by_public_id( $public_id );
		if ( ! $form instanceof Form ) {
			return AdminAccess::error( 'form_not_found', 404 );
		}

		return $form->id();
	}

	/**
	 * The submission addressed by this request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	private static function submission( WP_REST_Request $request ): Submission|WP_REST_Response {
		$receipt_id = $request->get_param( 'receipt_id' );
		$submission = ( new SubmissionRepository() )->find_by_receipt_id( is_string( $receipt_id ) ? $receipt_id : '' );
		if ( ! $submission instanceof Submission ) {
			return AdminAccess::error( 'submission_not_found', 404 );
		}

		return $submission;
	}

	/**
	 * Current form, loaded once per id while building a page.
	 *
	 * @param array<int, Form|null> $forms       Forms already loaded, keyed by id.
	 * @param Submission            $submission Submission on the page.
	 */
	private static function form_for( array &$forms, Submission $submission ): ?Form {
		$form_id = $submission->form_id();
		if ( ! array_key_exists( $form_id, $forms ) ) {
			$forms[ $form_id ] = ( new FormRepository() )->find_by_id( $form_id );
		}

		return $forms[ $form_id ];
	}

	/**
	 * A query argument as text.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $key     Parameter name.
	 */
	private static function text( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether a non-empty date is not a real UTC day.
	 *
	 * @param string $value Candidate `Y-m-d`. Empty is allowed.
	 */
	private static function bad_date( string $value ): bool {
		return '' !== $value && null === SubmissionQuery::date( $value );
	}
}
