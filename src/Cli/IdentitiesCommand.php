<?php
/**
 * WP-CLI command for the identity index.
 *
 * @package Formstead
 */

namespace Formstead\Cli;

use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Privacy\IdentityBackfill;

/**
 * Rebuilds the index of hashed identifying emails.
 */
final class IdentitiesCommand {

	/**
	 * Register the command when WP-CLI is loaded.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'formstead identities', self::class );
	}

	/**
	 * Rebuild the identity index.
	 *
	 * ## OPTIONS
	 *
	 * [--form=<public_id>]
	 * : Index one form as well as any backfill that is already waiting.
	 *
	 * @param string[]             $args       Positional arguments. Unused.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! class_exists( '\WP_CLI' ) ) {
			self::execute( $assoc_args );
			return;
		}

		foreach ( self::messages( self::execute( $assoc_args ) ) as $message ) {
			self::emit( $message );
		}
	}

	/**
	 * Run a rebuild and return a status code.
	 *
	 * `missing` means the public id does not match a form. `locked` means
	 * another run holds the lock. `pending` means a batch stopped early.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function execute( array $assoc_args ): string {
		$public = isset( $assoc_args['form'] ) && is_string( $assoc_args['form'] ) ? $assoc_args['form'] : '';
		if ( '' !== $public ) {
			$form = ( new FormRepository() )->find_by_public_id( $public );
			if ( ! $form instanceof Form ) {
				return 'missing';
			}

			IdentityBackfill::request_form( $form->id() );
		}

		return ( new IdentityBackfill() )->run( null, null );
	}

	/**
	 * Lines the command prints for a status code.
	 *
	 * @param string $result Status code from execute().
	 * @return list<array{level: string, text: string}>
	 */
	public static function messages( string $result ): array {
		if ( 'missing' === $result ) {
			return array( self::line( 'error', 'Form not found.' ) );
		}

		if ( 'locked' === $result ) {
			return array( self::line( 'warning', 'The identity index is already being built.' ) );
		}

		if ( 'pending' === $result ) {
			return array( self::line( 'warning', 'The identity index is still being built.' ) );
		}

		return array( self::line( 'success', 'The identity index is up to date.' ) );
	}

	/**
	 * Print one line at the level WP-CLI expects.
	 *
	 * @param array{level: string, text: string} $message Line to print.
	 */
	private static function emit( array $message ): void {
		if ( 'error' === $message['level'] ) {
			\WP_CLI::error( $message['text'] );
			return;
		}

		if ( 'warning' === $message['level'] ) {
			\WP_CLI::warning( $message['text'] );
			return;
		}

		if ( 'success' === $message['level'] ) {
			\WP_CLI::success( $message['text'] );
			return;
		}

		\WP_CLI::log( $message['text'] );
	}

	/**
	 * One command line.
	 *
	 * @param string $level `error`, `warning`, `success`, or `log`.
	 * @param string $text  Sentence to print.
	 * @return array{level: string, text: string}
	 */
	private static function line( string $level, string $text ): array {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}
}
