<?php
/**
 * One submission.
 *
 * @package Formstead
 *
 * @var Submission|null                                              $submission Stored submission, or null.
 * @var Form|null                                                    $form       Current form, or null.
 * @var array<int, array{label: string, value: string, multiline: bool, items: list<string>}> $rows Fields to show.
 * @var array<int, array{channel: string, status: string, attempts: string, last_error: string, next_attempt_at: string}> $deliveries Email deliveries.
 */

use Formstead\Admin\Menu;
use Formstead\Forms\Form;
use Formstead\Submissions\Submission;

defined( 'ABSPATH' ) || exit;

$submission = isset( $submission ) && $submission instanceof Submission ? $submission : null;
$form       = isset( $form ) && $form instanceof Form ? $form : null;
$rows       = isset( $rows ) && is_array( $rows ) ? $rows : array();
$deliveries = isset( $deliveries ) && is_array( $deliveries ) ? $deliveries : array();
$form_name  = $form instanceof Form ? $form->name() : '';
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Submission', 'formstead' ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Back to submissions', 'formstead' ); ?></a></p>

	<?php if ( ! $submission instanceof Submission ) : ?>
		<p><?php echo esc_html__( 'This submission could not be found.', 'formstead' ); ?></p>
	<?php else : ?>
		<p>
			<?php echo esc_html( $submission->created_at() ); ?>
			<?php if ( '' !== $form_name ) : ?>
				<?php echo esc_html( ' — ' . $form_name ); ?>
			<?php endif; ?>
		</p>
		<h2><?php echo esc_html__( 'Email', 'formstead' ); ?></h2>
		<?php if ( array() === $deliveries ) : ?>
			<p><?php echo esc_html__( 'No email was queued for this submission.', 'formstead' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Channel', 'formstead' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'formstead' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Attempts', 'formstead' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last error', 'formstead' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Next attempt', 'formstead' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $deliveries as $delivery ) : ?>
						<?php
						if ( ! is_array( $delivery ) ) {
							continue;
						}
						$channel    = isset( $delivery['channel'] ) ? (string) $delivery['channel'] : '';
						$status     = isset( $delivery['status'] ) ? (string) $delivery['status'] : '';
						$attempts   = isset( $delivery['attempts'] ) ? (string) $delivery['attempts'] : '';
						$last_error = isset( $delivery['last_error'] ) ? (string) $delivery['last_error'] : '';
						$next       = isset( $delivery['next_attempt_at'] ) ? (string) $delivery['next_attempt_at'] : '';
						?>
						<tr>
							<td><?php echo esc_html( $channel ); ?></td>
							<td><?php echo esc_html( $status ); ?></td>
							<td><?php echo esc_html( $attempts ); ?></td>
							<td><?php echo '' !== $last_error ? esc_html( $last_error ) : esc_html__( 'None', 'formstead' ); ?></td>
							<td><?php echo '' !== $next ? esc_html( $next ) : esc_html__( 'None', 'formstead' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					if ( ! is_array( $row ) ) {
						continue;
					}
					$label     = isset( $row['label'] ) ? (string) $row['label'] : '';
					$value     = isset( $row['value'] ) ? (string) $row['value'] : '';
					$multiline = ! empty( $row['multiline'] );
					$items     = isset( $row['items'] ) && is_array( $row['items'] ) ? $row['items'] : array();
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<?php if ( array() !== $items ) : ?>
								<ul>
									<?php foreach ( $items as $formstead_item ) : ?>
										<li><?php echo esc_html( (string) $formstead_item ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php elseif ( $multiline ) : ?>
								<?php echo nl2br( esc_html( $value ) ); ?>
							<?php else : ?>
								<?php echo esc_html( $value ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
