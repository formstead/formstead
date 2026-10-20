<?php
/**
 * Submissions list.
 *
 * @package Formstead
 *
 * @var Form[]                                                                 $forms      Forms for the filter.
 * @var int                                                                    $form_id    Selected form id, or zero.
 * @var array<int, array{url: string, created_at: string, form_name: string, excerpt: string}> $rows List rows.
 * @var string                                                                 $pagination Pagination markup from paginate_links().
 */

use Formstead\Admin\Menu;
use Formstead\Forms\Form;

defined( 'ABSPATH' ) || exit;

$forms      = isset( $forms ) && is_array( $forms ) ? $forms : array();
$form_id    = isset( $form_id ) ? (int) $form_id : 0;
$rows       = isset( $rows ) && is_array( $rows ) ? $rows : array();
$pagination = isset( $pagination ) ? (string) $pagination : '';
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Submissions', 'formstead' ); ?></h1>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Menu::MENU_SLUG ); ?>">
		<label for="formstead-form-filter"><?php echo esc_html__( 'Form', 'formstead' ); ?></label>
		<select id="formstead-form-filter" name="form_id">
			<option value="0"><?php echo esc_html__( 'All forms', 'formstead' ); ?></option>
			<?php foreach ( $forms as $form ) : ?>
				<?php if ( ! $form instanceof Form ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<option value="<?php echo esc_attr( (string) $form->id() ); ?>" <?php selected( $form_id, $form->id() ); ?>>
					<?php echo esc_html( $form->name() ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'formstead' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( array() === $rows ) : ?>
		<p><?php echo esc_html__( 'No submissions yet.', 'formstead' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Date', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Form', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Submission', 'formstead' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					if ( ! is_array( $row ) ) {
						continue;
					}
					$row_url     = isset( $row['url'] ) ? (string) $row['url'] : '';
					$created_at  = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
					$form_name   = isset( $row['form_name'] ) ? (string) $row['form_name'] : '';
					$row_excerpt = isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '';
					?>
					<tr>
						<td><a href="<?php echo esc_url( $row_url ); ?>"><?php echo esc_html( $created_at ); ?></a></td>
						<td><?php echo esc_html( $form_name ); ?></td>
						<td><?php echo esc_html( $row_excerpt ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( '' !== $pagination ) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $pagination ); ?></div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
