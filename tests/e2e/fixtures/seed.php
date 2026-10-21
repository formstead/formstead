<?php
/**
 * Seed the four forms used by browser tests.
 *
 * Prints one line, `FORMSTEAD_E2E_JSON` plus a JSON object. Run with
 * `wp eval-file` on the wp-env tests site, which defines FORMSTEAD_E2E.
 *
 * @package Formstead
 */

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormProtection;
use Formstead\Forms\FormRepository;
use Formstead\Forms\PublicId;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only, before WordPress loads.
	fwrite( STDERR, "Run this file with wp eval-file.\n" );
	exit( 1 );
}

if ( ! defined( 'FORMSTEAD_E2E' ) || ! FORMSTEAD_E2E ) {
	formstead_e2e_seed_fail( 'FORMSTEAD_E2E is not enabled.' );
}

/**
 * Stop the seed and tell WP-CLI why.
 *
 * @param string $message Failure, without submission contents.
 */
function formstead_e2e_seed_fail( string $message ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Public ids of the seeded forms.
 *
 * @return array<string, string>
 */
function formstead_e2e_public_ids(): array {
	return array(
		'same'   => 'fs_e2esameoriginxxx',
		'cross'  => 'fs_e2ecrossoriginxx',
		'closed' => 'fs_e2eclosedoriginx',
		'rate'   => 'fs_e2erateminutexxx',
	);
}

/**
 * Activate Formstead, the theme, and a known admin password.
 */
function formstead_e2e_prepare_site(): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( ! is_plugin_active( 'formstead/formstead.php' ) ) {
		$activated = activate_plugin( 'formstead/formstead.php' );
		if ( is_wp_error( $activated ) ) {
			formstead_e2e_seed_fail( 'Could not activate Formstead.' );
		}
	}

	if ( ! class_exists( Form::class ) ) {
		formstead_e2e_seed_fail( 'Formstead did not load.' );
	}

	$theme = wp_get_theme( 'twentytwentyfive' );
	if ( ! $theme->exists() ) {
		formstead_e2e_seed_fail( 'Twenty Twenty-Five is not installed.' );
	}

	switch_theme( 'twentytwentyfive' );

	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	flush_rewrite_rules();

	$admin = get_user_by( 'login', 'admin' );
	if ( ! $admin instanceof WP_User ) {
		formstead_e2e_seed_fail( 'The admin user is missing.' );
	}

	wp_set_password( 'password', $admin->ID );
	delete_option( 'formstead_e2e_fault' );
}

/**
 * Ids of forms this seed owns.
 *
 * @return list<int>
 */
function formstead_e2e_existing_form_ids(): array {
	global $wpdb;

	$table        = Schema::forms_table();
	$public_ids   = array_values( formstead_e2e_public_ids() );
	$placeholders = implode( ', ', array_fill( 0, count( $public_ids ), '%s' ) );
	$args         = array_merge( $public_ids, array( 'E2E %' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE public_id IN ( {$placeholders} ) OR name LIKE %s", ...$args ) );
	if ( ! is_array( $ids ) ) {
		return array();
	}

	return array_map( 'intval', $ids );
}

/**
 * Remove one seeded form and the rows that point at it.
 *
 * @param int $form_id Form id.
 */
function formstead_e2e_delete_form( int $form_id ): void {
	global $wpdb;

	$submissions = Schema::submissions_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	$submission_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE form_id = %d", $form_id ) );
	if ( is_array( $submission_ids ) ) {
		foreach ( $submission_ids as $submission_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->delete( Schema::deliveries_table(), array( 'submission_id' => (int) $submission_id ), array( '%d' ) );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
	$wpdb->delete( $submissions, array( 'form_id' => $form_id ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
	$wpdb->delete( Schema::idempotency_keys_table(), array( 'form_id' => $form_id ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
	$wpdb->delete( Schema::form_versions_table(), array( 'form_id' => $form_id ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
	$wpdb->delete( Schema::forms_table(), array( 'id' => $form_id ), array( '%d' ) );
}

/**
 * Remove seeded forms and every rate-limit counter.
 *
 * Counters are hashes, so one form cannot be selected with a WHERE clause.
 * This runs only on the tests site.
 */
function formstead_e2e_reset_rows(): void {
	global $wpdb;

	foreach ( formstead_e2e_existing_form_ids() as $form_id ) {
		formstead_e2e_delete_form( $form_id );
	}

	$table = Schema::rate_limits_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	$wpdb->query( "DELETE FROM {$table}" );
}

/**
 * Delete a page by slug, including its trash.
 *
 * @param string $slug Page slug.
 */
function formstead_e2e_delete_page( string $slug ): void {
	$pages = get_posts(
		array(
			'name'        => $slug,
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => 1,
		)
	);

	foreach ( $pages as $page ) {
		if ( $page instanceof WP_Post ) {
			wp_delete_post( $page->ID, true );
		}
	}
}

/**
 * Publish a page and return its permalink.
 *
 * @param string $slug  Page slug.
 * @param string $title Page title.
 * @param string $html  HTML for a Custom HTML block.
 */
function formstead_e2e_publish_page( string $slug, string $title, string $html ): string {
	formstead_e2e_delete_page( $slug );
	kses_remove_filters();
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->",
		),
		true
	);
	kses_init_filters();

	if ( is_wp_error( $page_id ) || ! is_int( $page_id ) || $page_id < 1 ) {
		formstead_e2e_seed_fail( 'Could not publish the ' . $slug . ' page.' );
	}

	$url = get_permalink( $page_id );
	if ( ! is_string( $url ) || '' === $url ) {
		formstead_e2e_seed_fail( 'Could not read the ' . $slug . ' permalink.' );
	}

	return $url;
}

/**
 * Markup for the same-site form.
 *
 * The HTML example replaces this when that file exists. The placeholder is
 * the submissions endpoint, escaped for an attribute.
 *
 * @param string $endpoint Absolute submissions URL.
 */
function formstead_e2e_same_site_markup( string $endpoint ): string {
	$path = dirname( __DIR__, 3 ) . '/examples/html/same-site.html';
	if ( is_readable( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture, not a remote URL.
		$html = file_get_contents( $path );
		if ( ! is_string( $html ) || ! str_contains( $html, '__FORMSTEAD_ENDPOINT__' ) ) {
			formstead_e2e_seed_fail( 'The same-site example is missing its endpoint placeholder.' );
		}
	} else {
		$html = <<<'HTML'
<form method="post" action="__FORMSTEAD_ENDPOINT__">
<p><label for="email">Email</label><br><input id="email" name="email" type="email" autocomplete="email" required></p>
<p><label for="message">Message</label><br><textarea id="message" name="message" required></textarea></p>
<div style="position:absolute;left:-10000px;height:1px;width:1px;overflow:hidden" aria-hidden="true">
<label for="formstead-honeypot">Company</label>
<input id="formstead-honeypot" type="text" name="_fs_honeypot" tabindex="-1" autocomplete="off">
</div>
<button type="submit">Send</button>
</form>
HTML;
	}

	return str_replace( '__FORMSTEAD_ENDPOINT__', esc_attr( $endpoint ), $html );
}

/**
 * Fields shared by the seeded forms.
 *
 * @return list<Field>
 */
function formstead_e2e_fields(): array {
	return array(
		new Field( 'email', 'Email', FieldType::Email, true, 255 ),
		new Field( 'message', 'Message', FieldType::Textarea, true, 10000 ),
	);
}

/**
 * Store one form and force its public id.
 *
 * @param string   $name         Admin name.
 * @param string   $public_id   Fixed public id.
 * @param string   $success_url Absolute success URL.
 * @param string[] $origins     Allowlist. Empty means this site only.
 * @param int      $per_minute  Per-minute override.
 * @param int      $per_hour    Per-hour override.
 * @param string[] $recipients  Notification recipients.
 */
function formstead_e2e_insert_form( string $name, string $public_id, string $success_url, array $origins, int $per_minute, int $per_hour, array $recipients ): void {
	global $wpdb;

	if ( ! PublicId::is_valid( $public_id ) ) {
		formstead_e2e_seed_fail( 'A seeded public id is not valid.' );
	}

	$notifications = array() === $recipients
		? FormNotifications::none()
		: new FormNotifications( $recipients, 'email', false );

	$saved = ( new FormRepository() )->insert(
		new Form(
			0,
			'',
			$name,
			true,
			formstead_e2e_fields(),
			1,
			$success_url,
			'',
			'',
			new FormProtection( $origins, $per_minute, $per_hour ),
			0,
			$notifications,
			30,
			array( 'email' )
		)
	);

	if ( ! $saved instanceof Form ) {
		formstead_e2e_seed_fail( 'Could not save a seeded form.' );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table. The repository assigns a random id.
	$updated = $wpdb->update(
		Schema::forms_table(),
		array( 'public_id' => $public_id ),
		array( 'id' => $saved->id() ),
		array( '%s' ),
		array( '%d' )
	);
	if ( false === $updated ) {
		formstead_e2e_seed_fail( 'Could not assign a seeded public id.' );
	}

	$stored = ( new FormRepository() )->find_by_public_id( $public_id );
	if ( ! $stored instanceof Form || $stored->id() !== $saved->id() ) {
		formstead_e2e_seed_fail( 'The seeded form could not be read back.' );
	}
}

/**
 * Submissions URL for a public id.
 *
 * @param string $public_id Public id.
 */
function formstead_e2e_endpoint( string $public_id ): string {
	return rest_url( 'formstead/v1/forms/' . $public_id . '/submissions' );
}

/**
 * Create the four forms and print their JSON description.
 */
function formstead_e2e_seed(): void {
	formstead_e2e_prepare_site();
	formstead_e2e_reset_rows();

	$thanks_url = formstead_e2e_publish_page(
		'e2e-thanks',
		'Thanks',
		'<main><h1>Thanks</h1><p>The form was sent.</p></main>'
	);

	$ids         = formstead_e2e_public_ids();
	$example     = array( 'http://localhost:4400', 'http://localhost:4321', 'http://localhost:4500' );
	$thanks_html = 'http://localhost:4400/thanks.html';

	formstead_e2e_insert_form( 'E2E same site', $ids['same'], $thanks_url, array(), 60, 10000, array() );
	formstead_e2e_insert_form( 'E2E cross origin', $ids['cross'], $thanks_html, $example, 60, 10000, array( 'owner@example.com' ) );
	formstead_e2e_insert_form( 'E2E closed', $ids['closed'], 'https://example.com/thanks', array( 'https://forms.example' ), 60, 10000, array() );
	formstead_e2e_insert_form( 'E2E rate limit', $ids['rate'], $thanks_html, $example, 3, 10000, array() );

	$same_endpoint = formstead_e2e_endpoint( $ids['same'] );
	$page_url      = formstead_e2e_publish_page(
		'e2e-form',
		'E2E form',
		formstead_e2e_same_site_markup( $same_endpoint )
	);

	$payload = array(
		'base_url' => untrailingslashit( home_url() ),
		'forms'    => array(
			'same'   => array(
				'public_id'   => $ids['same'],
				'endpoint'    => $same_endpoint,
				'success_url' => $thanks_url,
				'page_url'    => $page_url,
			),
			'cross'  => array(
				'public_id'   => $ids['cross'],
				'endpoint'    => formstead_e2e_endpoint( $ids['cross'] ),
				'success_url' => $thanks_html,
			),
			'closed' => array(
				'public_id'   => $ids['closed'],
				'endpoint'    => formstead_e2e_endpoint( $ids['closed'] ),
				'success_url' => 'https://example.com/thanks',
			),
			'rate'   => array(
				'public_id'   => $ids['rate'],
				'endpoint'    => formstead_e2e_endpoint( $ids['rate'] ),
				'success_url' => $thanks_html,
			),
		),
	);

	$encoded = wp_json_encode( $payload );
	if ( ! is_string( $encoded ) ) {
		formstead_e2e_seed_fail( 'Could not encode the seed result.' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
	fwrite( STDOUT, 'FORMSTEAD_E2E_JSON ' . $encoded . "\n" );
}

formstead_e2e_seed();
