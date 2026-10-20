<?php
/**
 * Forms list.
 *
 * @package Formstead
 *
 * @var Form[] $forms Forms to list.
 */

use Formstead\Admin\Menu;
use Formstead\Forms\Form;

defined( 'ABSPATH' ) || exit;

$forms = isset( $forms ) && is_array( $forms ) ? $forms : array();
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html__( 'Forms', 'formstead' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::FORMS_SLUG . '&action=new' ) ); ?>" class="page-title-action">
		<?php echo esc_html__( 'Add form', 'formstead' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( array() === $forms ) : ?>
		<p><?php echo esc_html__( 'No forms yet.', 'formstead' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Name', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Public identifier', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Honeypot', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Updated', 'formstead' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $forms as $form ) : ?>
					<?php
					if ( ! $form instanceof Form ) {
						continue;
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::FORMS_SLUG . '&form=' . $form->id() ) ); ?>">
								<?php echo esc_html( $form->name() ); ?>
							</a>
						</td>
						<td>
							<?php
							echo $form->is_enabled()
								? esc_html__( 'Enabled', 'formstead' )
								: esc_html__( 'Disabled', 'formstead' );
							?>
						</td>
						<td><code><?php echo esc_html( $form->public_id() ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $form->honeypot_rejections() ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $form->updated_at(), 'Y-m-d H:i' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
