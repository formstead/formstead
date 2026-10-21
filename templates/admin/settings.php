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
 */

use Formstead\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$report        = isset( $report ) && is_array( $report ) ? $report : null;
$overdue       = isset( $overdue ) && $overdue;
$spam_days     = isset( $spam_days ) ? (int) $spam_days : 0;
$delivery_days = isset( $delivery_days ) ? (int) $delivery_days : 0;
$warning       = isset( $warning ) ? (string) $warning : '';
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Settings', 'formstead' ); ?></h1>

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
</div>
