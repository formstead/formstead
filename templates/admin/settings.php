<?php
/**
 * Retention settings.
 *
 * @package Formstead
 *
 * @var array{finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, errors: list<string>}|null $report
 * @var bool   $overdue
 * @var int    $spam_days
 * @var int    $delivery_days
 * @var string $warning
 * @var int    $next_run
 * @var bool   $delete_data
 * @var bool   $can_delete_data
 * @var bool   $updated
 */

use Formstead\Admin\Menu;
use Formstead\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$report          = isset( $report ) && is_array( $report ) ? $report : null;
$overdue         = isset( $overdue ) && $overdue;
$spam_days       = isset( $spam_days ) ? (int) $spam_days : 0;
$delivery_days   = isset( $delivery_days ) ? (int) $delivery_days : 0;
$warning         = isset( $warning ) ? (string) $warning : '';
$next_run        = isset( $next_run ) ? (int) $next_run : 0;
$delete_data     = isset( $delete_data ) && $delete_data;
$can_delete_data = isset( $can_delete_data ) && $can_delete_data;
$updated         = isset( $updated ) && $updated;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Settings', 'formstead' ); ?></h1>

	<?php if ( $updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'formstead' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $overdue ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
	<?php endif; ?>

	<h2><?php echo esc_html__( 'Data retention', 'formstead' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php echo esc_html__( 'Spam', 'formstead' ); ?></th>
			<td>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of days, 2: PHP constant name. */
						__( '%1$s days. Constant: %2$s.', 'formstead' ),
						number_format_i18n( $spam_days ),
						'FORMSTEAD_SPAM_RETENTION_DAYS'
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Finished deliveries', 'formstead' ); ?></th>
			<td>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of days, 2: PHP constant name. */
						__( '%1$s days. Constant: %2$s.', 'formstead' ),
						number_format_i18n( $delivery_days ),
						'FORMSTEAD_DELIVERY_RETENTION_DAYS'
					)
				);
				?>
			</td>
		</tr>
	</table>

	<h2><?php echo esc_html__( 'Last run', 'formstead' ); ?></h2>
	<?php if ( null === $report || $report['finished_at'] < 1 ) : ?>
		<p><?php echo esc_html__( 'Retention has not run yet.', 'formstead' ); ?></p>
		<?php if ( $next_run > 0 ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: date and time of the first retention run. */
						__( 'The first run is scheduled for %s.', 'formstead' ),
						wp_date( 'Y-m-d H:i', $next_run )
					)
				);
				?>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Finished', 'formstead' ); ?></th>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $report['finished_at'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Submissions', 'formstead' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $report['submissions'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Spam', 'formstead' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $report['spam'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Deliveries', 'formstead' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $report['deliveries'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Idempotency keys', 'formstead' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $report['keys'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Rate-limit counters', 'formstead' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $report['rate_limits'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Rows still waiting', 'formstead' ); ?></th>
					<td><?php echo esc_html( $report['backlog'] ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' ) ); ?></td>
				</tr>
				<?php if ( array() !== $report['errors'] ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Errors', 'formstead' ); ?></th>
						<td>
							<?php foreach ( $report['errors'] as $code ) : ?>
								<p><?php echo esc_html( SettingsPage::error_text( $code ) ); ?></p>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php echo esc_html__( 'Uninstall', 'formstead' ); ?></h2>
	<?php if ( $can_delete_data ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SETTINGS_SLUG ) ); ?>">
			<?php wp_nonce_field( 'formstead_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Data', 'formstead' ); ?></th>
					<td>
						<label for="formstead-delete-data">
							<input name="delete_data_on_uninstall" id="formstead-delete-data" type="checkbox" value="1" <?php checked( $delete_data ); ?>>
							<?php echo esc_html__( 'Delete all data on uninstall', 'formstead' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Deletes tables, settings, and capabilities when the plugin is uninstalled. Leave this off to keep submissions.', 'formstead' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'formstead' ) ); ?>
		</form>
	<?php else : ?>
		<p><?php echo esc_html__( 'Only a person who can activate plugins can choose to delete data on uninstall.', 'formstead' ); ?></p>
	<?php endif; ?>
</div>
