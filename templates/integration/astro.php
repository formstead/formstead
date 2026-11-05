<?php
/**
 * Standalone Astro component with native POST and configurable IDs.
 *
 * @package Formstead
 * @var \Formstead\Forms\Form $form Saved form.
 * @var string $endpoint Intake URL.
 * @var string $prefix Default instance prefix.
 */

defined( 'ABSPATH' ) || exit;
$data = array(
	'endpoint'  => $endpoint,
	'prefix'    => $prefix,
	'fields'    => array_map( static fn( $field ) => $field->to_array(), $form->fields() ),
	'send'      => __( 'Send', 'formstead' ),
	'company'   => __( 'Company', 'formstead' ),
	'choose'    => __( 'Choose an option', 'formstead' ),
	/* translators: %s: maximum decimal places. */
	'precision' => __( 'Use no more than %s decimal places.', 'formstead' ),
);
?>
---
interface Props {
	idPrefix?: string;
}
interface Field {
	name: string;
	label: string;
	type: string;
	required: boolean;
	max_length: number;
	min?: string;
	max?: string;
	scale?: number;
	multiple?: boolean;
	options?: Array<{ value: string; label: string }>;
}
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX protects component source from arbitrary schema text. ?>
const config = <?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
const { idPrefix = config.prefix } = Astro.props;
const fields: Field[] = config.fields;
---
<form id={idPrefix} class="formstead-form" method="post" action={config.endpoint}>
	{fields.map((field) => {
	const id = `${idPrefix}-${field.name}`;
	return (
		<div class="formstead-field">
		<label for={id}>{field.label || field.name}</label>
		{field.type === 'textarea' ? (
			<textarea id={id} name={field.name} required={field.required} maxlength={field.max_length}></textarea>
		) : field.type === 'choice' ? (
			<select id={id} name={field.name + (field.multiple ? '[]' : '')} multiple={field.multiple} required={field.required}>
			{!field.multiple && <option value="">{config.choose}</option>}
			{(field.options ?? []).map((option) => <option value={option.value}>{option.label}</option>)}
			</select>
		) : field.type === 'number' ? (
			<>
			<input id={id} name={field.name} type="number" step="any" min={field.min} max={field.max} required={field.required} aria-describedby={`${id}-hint`} />
			<p id={`${id}-hint`}>{config.precision.replace('%s', String(field.scale))}</p>
			</>
		) : field.type === 'boolean' ? (
			<input id={id} name={field.name} type="checkbox" value="1" required={field.required} />
		) : (
			<input id={id} name={field.name} type={field.type} required={field.required} maxlength={field.max_length} autocomplete={field.type === 'email' ? 'email' : undefined} />
		)}
		</div>
	);
	})}
	<div aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap">
	<label for={`${idPrefix}-_fs_honeypot`}>{config.company}</label>
	<input id={`${idPrefix}-_fs_honeypot`} name="_fs_honeypot" type="text" value="" tabindex="-1" autocomplete="off" />
	</div>
	<button type="submit">{config.send}</button>
</form>
