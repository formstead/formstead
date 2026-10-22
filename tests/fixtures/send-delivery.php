<?php
/**
 * One isolated delivery worker against the parent's already installed test DB.
 *
 * @package Formstead
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- standalone child fixture; no shell, network receiver, or database installer.

define( 'ABSPATH', (string) getenv( 'FORMSTEAD_WP_PATH' ) );
define( 'DB_NAME', (string) getenv( 'FORMSTEAD_DB_NAME' ) );
define( 'DB_USER', (string) getenv( 'FORMSTEAD_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'FORMSTEAD_DB_PASSWORD' ) );
define( 'DB_HOST', (string) getenv( 'FORMSTEAD_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- this standalone WordPress bootstrap must select the parent's test prefix.
$table_prefix = (string) getenv( 'FORMSTEAD_DB_PREFIX' );
require ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$barrier  = (string) getenv( 'FORMSTEAD_WORKER_BARRIER' );
$deadline = microtime( true ) + 10;
while ( ! is_file( $barrier ) ) {
	if ( microtime( true ) > $deadline ) {
		exit( 2 );
	}
	usleep( 10000 );
}

$channel = new class() implements \Formstead\Delivery\Channel {
	/**
	 * Channel selected by the synthetic delivery.
	 */
	public function name(): string {
		return 'email';
	}
	/**
	 * This fixture always has a local capture file.
	 *
	 * @param \Formstead\Forms\Form $form Stored form. Unused.
	 */
	public function is_configured( \Formstead\Forms\Form $form ): bool {
		unset( $form );
		return true;
	}
	/**
	 * Record one handoff without exposing the submission or contacting a service.
	 *
	 * @param \Formstead\Submissions\Submission $submission Stored submission. Unused.
	 * @param \Formstead\Forms\Form             $form       Stored form. Unused.
	 */
	public function send( \Formstead\Submissions\Submission $submission, \Formstead\Forms\Form $form ): ?string {
		unset( $submission, $form );
		file_put_contents( (string) getenv( 'FORMSTEAD_WORKER_CAPTURE' ), "sent\n", FILE_APPEND | LOCK_EX );
		usleep( 200000 );
		return null;
	}
};
( new \Formstead\Delivery\DeliveryWorker( $channel ) )->send( (int) getenv( 'FORMSTEAD_DELIVERY_ID' ) );
