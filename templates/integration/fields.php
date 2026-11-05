<?php
/**
 * HTML controls shared by integration variants.
 *
 * @package Formstead
 * @var \Formstead\Forms\Form $form Persisted form.
 * @var string $prefix Instance ID prefix.
 */

use Formstead\Forms\FieldType;

defined( 'ABSPATH' ) || exit;

foreach ( $form->fields() as $field ) :
	$id       = $prefix . '-' . $field->name();
	$type     = $field->type();
	$settings = $field->settings();
	$label    = '' !== $field->label() ? $field->label() : $field->name();
	$tag      = match ( $type ) {
		FieldType::Textarea => 'textarea',
		FieldType::Choice => 'select',
		default => 'input',
	};
	$attributes = array(
		'id'   => $id,
		'name' => $field->name(),
	);
	if ( $field->required() ) {
		$attributes['required'] = 'required';
	}
	if ( 'input' === $tag ) {
		$attributes['type'] = FieldType::Boolean === $type ? 'checkbox' : $type->value;
	}
	if ( in_array( $type, array( FieldType::Text, FieldType::Email, FieldType::Textarea ), true ) ) {
		$attributes['maxlength'] = (string) $field->max_length();
	}
	if ( FieldType::Email === $type ) {
		$attributes['autocomplete'] = 'email';
	}
	if ( FieldType::Boolean === $type ) {
		$attributes['value'] = '1';
	}
	if ( FieldType::Number === $type ) {
		$attributes['step']             = 'any';
		$attributes['aria-describedby'] = $id . '-hint';
		if ( null !== $settings->min() ) {
			$attributes['min'] = $settings->min();
		}
		if ( null !== $settings->max() ) {
			$attributes['max'] = $settings->max();
		}
	}
	if ( FieldType::Choice === $type && $settings->multiple() ) {
		$attributes['name']    .= '[]';
		$attributes['multiple'] = 'multiple';
	}
	if ( $enhanced ) {
		$attributes['data-formstead-field'] = $field->name();
		$attributes['aria-describedby']     = isset( $attributes['aria-describedby'] )
			? $attributes['aria-describedby'] . ' ' . $id . '-error'
			: $id . '-error';
	}
	?>
	<div class="formstead-field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<?php
		echo '<' . esc_attr( $tag );
		foreach ( $attributes as $attribute => $value ) {
			echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( $value ) . '"';
		}
		echo '>';
		if ( FieldType::Choice === $type ) {
			if ( ! $settings->multiple() ) {
				echo "\n\t\t\t";
				echo '<option value="">' . esc_html__( 'Choose an option', 'formstead' ) . '</option>';
			}
			foreach ( $settings->options() as $option ) {
				echo "\n\t\t\t" . '<option value="' . esc_attr( $option['value'] ) . '">' . esc_html( $option['label'] ) . '</option>';
			}
			echo "\n\t\t";
		}
		if ( 'input' !== $tag ) {
			echo '</' . esc_attr( $tag ) . '>';
		}
		echo "\n";
		?>
		<?php if ( FieldType::Number === $type ) : ?>
		<p id="<?php echo esc_attr( $id . '-hint' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: maximum decimal places. */ __( 'Use no more than %d decimal places.', 'formstead' ), $settings->scale() ) ); ?></p>
		<?php endif; ?>
		<?php if ( $enhanced ) : ?>
		<p id="<?php echo esc_attr( $id . '-error' ); ?>" data-formstead-error="<?php echo esc_attr( $field->name() ); ?>" hidden></p>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
