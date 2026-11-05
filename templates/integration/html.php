<?php
/**
 * Standalone HTML source. Included by CodeGenerator in local scope.
 *
 * @package Formstead
 * @var \Formstead\Forms\Form $form Persisted form.
 * @var string $endpoint Intake URL.
 * @var string $prefix Instance ID prefix.
 */

defined( 'ABSPATH' ) || exit;
?>
<form id="<?php echo esc_attr( $prefix ); ?>" class="formstead-form" method="post" action="<?php echo esc_url( $endpoint ); ?>">
<?php require __DIR__ . '/fields.php'; ?>
	<div aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap">
		<label for="<?php echo esc_attr( $prefix . '-_fs_honeypot' ); ?>"><?php echo esc_html__( 'Company', 'formstead' ); ?></label>
		<input id="<?php echo esc_attr( $prefix . '-_fs_honeypot' ); ?>" type="text" name="_fs_honeypot" value="" tabindex="-1" autocomplete="off">
	</div>
	<button type="submit"><?php echo esc_html__( 'Send', 'formstead' ); ?></button>
	<?php if ( $enhanced ) : ?>
	<p role="status" aria-atomic="true" data-formstead-status></p>
	<?php endif; ?>
</form>
