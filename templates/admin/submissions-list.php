<?php
/**
 * Submissions list.
 *
 * @package Formstead
 *
 * @var Form[]           $forms  Forms for the filter.
 * @var SubmissionQuery  $query  Active filters.
 * @var SubmissionsTable $table  Inbox table.
 * @var string           $notice Result of the previous action.
 */

use Formstead\Admin\Menu;
use Formstead\Admin\SubmissionsTable;
use Formstead\Forms\Form;
use Formstead\Submissions\SubmissionQuery;

defined( 'ABSPATH' ) || exit;

$forms     = isset( $forms ) && is_array( $forms ) ? $forms : array();
$query     = isset( $query ) && $query instanceof SubmissionQuery ? $query : SubmissionQuery::from_values( 0, '', '', '' );
$table     = isset( $table ) && $table instanceof SubmissionsTable ? $table : null;
$notice    = isset( $notice ) ? (string) $notice : '';
$form_id   = (int) $query->form_id();
$date_from = $query->date_from() ?? '';
$date_to   = $query->date_to() ?? '';
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html__( 'Submissions', 'formstead' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Menu::MENU_SLUG ); ?>">
		<input type="hidden" name="inbox_view" value="<?php echo esc_attr( $query->view() ); ?>">
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
		<label for="formstead-date-from"><?php echo esc_html__( 'From', 'formstead' ); ?></label>
		<input type="date" id="formstead-date-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
		<label for="formstead-date-to"><?php echo esc_html__( 'To', 'formstead' ); ?></label>
		<input type="date" id="formstead-date-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
		<?php submit_button( __( 'Filter', 'formstead' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( $table instanceof SubmissionsTable ) : ?>
		<form method="post">
			<?php $table->display(); ?>
		</form>
	<?php endif; ?>
</div>
