<?php
/**
 * Add or edit a form.
 *
 * @package Formstead
 *
 * @var Form|null              $form    Saved form, or null when creating one.
 * @var array<string, mixed>   $values  Values to show in the form.
 * @var array<string, string>  $errors  Field errors.
 * @var bool                   $updated Whether the form was just saved.
 */

use Formstead\Admin\FormsPage;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;

defined( 'ABSPATH' ) || exit;

$values  = isset( $values ) && is_array( $values ) ? $values : array();
$errors  = isset( $errors ) && is_array( $errors ) ? $errors : array();
$updated = isset( $updated ) && $updated;
$form    = isset( $form ) && $form instanceof Form ? $form : null;

$form_id     = isset( $values['form_id'] ) ? (int) $values['form_id'] : 0;
$name        = isset( $values['name'] ) ? (string) $values['name'] : '';
$is_enabled  = isset( $values['is_enabled'] ) && '1' === (string) $values['is_enabled'];
$success_url = isset( $values['success_url'] ) ? (string) $values['success_url'] : '';
$rows        = isset( $values['fields'] ) && is_array( $values['fields'] ) ? $values['fields'] : array();
$endpoint    = $form instanceof Form ? rest_url( 'formstead/v1/forms/' . $form->public_id() . '/submissions' ) : '';
$next_index  = count( $rows );
?>
<div class="wrap">
	<h1><?php echo $form instanceof Form ? esc_html__( 'Edit form', 'formstead' ) : esc_html__( 'Add form', 'formstead' ); ?></h1>

	<?php if ( $updated ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html__( 'Form saved.', 'formstead' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $errors['form'] ) || isset( $errors['fields'] ) ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $errors['form'] ?? $errors['fields'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=formstead-forms' ) ); ?>">
		<?php wp_nonce_field( 'formstead_save_form' ); ?>
		<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="formstead-name"><?php echo esc_html__( 'Name', 'formstead' ); ?></label></th>
				<td>
					<input name="name" id="formstead-name" type="text" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required>
					<?php if ( isset( $errors['name'] ) ) : ?>
						<p class="description"><?php echo esc_html( $errors['name'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Status', 'formstead' ); ?></th>
				<td>
					<label for="formstead-enabled">
						<input name="is_enabled" id="formstead-enabled" type="checkbox" value="1" <?php checked( $is_enabled ); ?>>
						<?php echo esc_html__( 'Accept submissions', 'formstead' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="formstead-success-url"><?php echo esc_html__( 'Success URL', 'formstead' ); ?></label></th>
				<td>
					<input name="success_url" id="formstead-success-url" type="url" class="regular-text code" value="<?php echo esc_attr( $success_url ); ?>" required>
					<?php if ( isset( $errors['success_url'] ) ) : ?>
						<p class="description"><?php echo esc_html( $errors['success_url'] ); ?></p>
					<?php else : ?>
						<p class="description"><?php echo esc_html__( 'Absolute http or https URL. The form never redirects to an address from the request.', 'formstead' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( '' !== $endpoint ) : ?>
				<tr>
					<th scope="row"><label for="formstead-endpoint"><?php echo esc_html__( 'Endpoint URL', 'formstead' ); ?></label></th>
					<td>
						<input id="formstead-endpoint" type="text" class="large-text code" readonly value="<?php echo esc_attr( $endpoint ); ?>">
						<p class="description"><?php echo esc_html__( 'The public identifier is not a secret. Anyone with this URL can submit the form.', 'formstead' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<h2><?php echo esc_html__( 'Fields', 'formstead' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Name', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Label', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Type', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Required', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Max length', 'formstead' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Remove', 'formstead' ); ?></th>
				</tr>
			</thead>
			<tbody id="formstead-fields" data-next-index="<?php echo esc_attr( (string) $next_index ); ?>">
				<?php foreach ( $rows as $index => $row ) : ?>
					<?php
					if ( ! is_array( $row ) ) {
						continue;
					}
					$row_name  = isset( $row['name'] ) ? (string) $row['name'] : '';
					$row_label = isset( $row['label'] ) ? (string) $row['label'] : '';
					$row_type  = isset( $row['type'] ) ? (string) $row['type'] : '';
					$row_max   = isset( $row['max_length'] ) ? (string) $row['max_length'] : '';
					$required  = isset( $row['required'] ) && '1' === (string) $row['required'];
					$prefix    = 'fields.' . $index;
					?>
					<tr data-field-row>
						<td>
							<input name="fields[<?php echo esc_attr( (string) $index ); ?>][name]" type="text" value="<?php echo esc_attr( $row_name ); ?>" class="code" pattern="[a-z][a-z0-9_]{0,63}" autocomplete="off">
							<?php if ( isset( $errors[ $prefix . '.name' ] ) ) : ?>
								<p class="description"><?php echo esc_html( $errors[ $prefix . '.name' ] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<input name="fields[<?php echo esc_attr( (string) $index ); ?>][label]" type="text" value="<?php echo esc_attr( $row_label ); ?>">
							<?php if ( isset( $errors[ $prefix . '.label' ] ) ) : ?>
								<p class="description"><?php echo esc_html( $errors[ $prefix . '.label' ] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<select name="fields[<?php echo esc_attr( (string) $index ); ?>][type]">
								<option value=""><?php echo esc_html__( 'Select', 'formstead' ); ?></option>
								<?php foreach ( FieldType::cases() as $type ) : ?>
									<option value="<?php echo esc_attr( $type->value ); ?>" <?php selected( $row_type, $type->value ); ?>>
										<?php echo esc_html( FormsPage::type_label( $type->value ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( isset( $errors[ $prefix . '.type' ] ) ) : ?>
								<p class="description"><?php echo esc_html( $errors[ $prefix . '.type' ] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<input name="fields[<?php echo esc_attr( (string) $index ); ?>][required]" type="checkbox" value="1" <?php checked( $required ); ?>>
						</td>
						<td>
							<input name="fields[<?php echo esc_attr( (string) $index ); ?>][max_length]" type="number" min="1" max="10000" value="<?php echo esc_attr( $row_max ); ?>" class="small-text">
							<?php if ( isset( $errors[ $prefix . '.max_length' ] ) ) : ?>
								<p class="description"><?php echo esc_html( $errors[ $prefix . '.max_length' ] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<label data-remove-fallback>
								<input name="fields[<?php echo esc_attr( (string) $index ); ?>][remove]" type="checkbox" value="1">
								<?php echo esc_html__( 'Remove', 'formstead' ); ?>
							</label>
							<button type="button" class="button-link" data-remove-field hidden><?php echo esc_html__( 'Remove', 'formstead' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="formstead-add-field"><?php echo esc_html__( 'Add field', 'formstead' ); ?></button>
		</p>

		<template id="formstead-field-template">
			<tr data-field-row>
				<td><input name="fields[__INDEX__][name]" type="text" value="" class="code" pattern="[a-z][a-z0-9_]{0,63}" autocomplete="off"></td>
				<td><input name="fields[__INDEX__][label]" type="text" value=""></td>
				<td>
					<select name="fields[__INDEX__][type]">
						<option value=""><?php echo esc_html__( 'Select', 'formstead' ); ?></option>
						<?php foreach ( FieldType::cases() as $type ) : ?>
							<option value="<?php echo esc_attr( $type->value ); ?>"><?php echo esc_html( FormsPage::type_label( $type->value ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td><input name="fields[__INDEX__][required]" type="checkbox" value="1"></td>
				<td><input name="fields[__INDEX__][max_length]" type="number" min="1" max="10000" value="" class="small-text"></td>
				<td>
					<label data-remove-fallback>
						<input name="fields[__INDEX__][remove]" type="checkbox" value="1">
						<?php echo esc_html__( 'Remove', 'formstead' ); ?>
					</label>
					<button type="button" class="button-link" data-remove-field hidden><?php echo esc_html__( 'Remove', 'formstead' ); ?></button>
				</td>
			</tr>
		</template>

	<?php submit_button( $form instanceof Form ? __( 'Save form', 'formstead' ) : __( 'Create form', 'formstead' ) ); ?>
</form>
</div>
