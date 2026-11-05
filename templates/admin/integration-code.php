<?php
/**
 * Readonly source for the last saved form, outside the editor's POST form.
 *
 * @package Formstead
 * @var \Formstead\Forms\Form|null $form Persisted form, if any.
 * @var string $integration_code Generated HTML, or empty for a new form.
 * @var string $integration_javascript Generated enhanced HTML.
 * @var bool $integration_unsaved Whether the last save failed.
 */

defined( 'ABSPATH' ) || exit;
?>
<section aria-labelledby="formstead-integration-heading">
	<h2 id="formstead-integration-heading"><?php echo esc_html__( 'Integration code', 'formstead' ); ?></h2>
	<?php if ( '' === $integration_code ) : ?>
		<p><?php echo esc_html__( 'Save the form to generate integration code.', 'formstead' ); ?></p>
	<?php else : ?>
		<p><?php echo esc_html__( 'This code uses the last saved configuration.', 'formstead' ); ?></p>
		<p id="formstead-platform-controls" hidden>
			<label for="formstead-integration-platform"><?php echo esc_html__( 'Platform', 'formstead' ); ?></label>
			<select id="formstead-integration-platform">
				<option value="html">HTML</option>
				<option value="javascript">JavaScript / fetch</option>
				<option value="astro">Astro</option>
				<option value="react">React / Next.js</option>
			</select>
		</p>
		<p id="formstead-integration-unsaved" role="status"
		<?php
		if ( ! $integration_unsaved ) :
			?>
			hidden<?php endif; ?>><?php echo esc_html__( 'Unsaved changes. This code uses the last saved configuration.', 'formstead' ); ?></p>
		<?php if ( ! $form->is_enabled() ) : ?>
			<p><?php echo esc_html__( 'This form is disabled and does not accept submissions.', 'formstead' ); ?></p>
		<?php endif; ?>
		<div data-integration-panel="html">
			<p><label for="formstead-integration-html"><?php echo esc_html__( 'HTML code', 'formstead' ); ?></label></p>
			<textarea id="formstead-integration-html" class="large-text code" rows="18" readonly spellcheck="false" aria-describedby="formstead-integration-instructions"><?php echo esc_textarea( $integration_code ); ?></textarea>
			<p id="formstead-integration-instructions"><?php echo esc_html__( 'Paste this HTML where the form should appear on your site and adapt the labels and styling. It works without JavaScript. A successful submission redirects to the saved Success URL.', 'formstead' ); ?></p>
		</div>
		<div data-integration-panel="javascript">
			<p><label for="formstead-integration-javascript"><?php echo esc_html__( 'JavaScript / fetch code', 'formstead' ); ?></label></p>
			<textarea id="formstead-integration-javascript" class="large-text code" rows="18" readonly spellcheck="false" aria-describedby="formstead-integration-javascript-instructions"><?php echo esc_textarea( $integration_javascript ); ?></textarea>
			<p id="formstead-integration-javascript-instructions"><?php echo esc_html__( 'Paste the complete HTML and script together where the form should appear, then adapt the labels and styling. With JavaScript it shows confirmation without leaving the page; without JavaScript it redirects to the saved Success URL. No build step or SDK is required.', 'formstead' ); ?></p>
		</div>
		<div data-integration-panel="astro">
			<p><label for="formstead-integration-astro"><?php echo esc_html__( 'Astro component', 'formstead' ); ?></label></p>
			<textarea id="formstead-integration-astro" class="large-text code" rows="18" readonly spellcheck="false" aria-describedby="formstead-astro-instructions"><?php echo esc_textarea( $integration_astro ); ?></textarea>
			<p id="formstead-astro-instructions"><?php echo esc_html__( 'Save as src/components/FormsteadForm.astro. Import it into a page and render <FormsteadForm />. Use a unique idPrefix prop for each copy on the same page. This component posts without JavaScript and redirects to the saved Success URL.', 'formstead' ); ?></p>
		</div>
		<div data-integration-panel="react">
			<p><label for="formstead-integration-react"><?php echo esc_html__( 'React / Next.js component', 'formstead' ); ?></label></p>
			<textarea id="formstead-integration-react" class="large-text code" rows="18" readonly spellcheck="false" aria-describedby="formstead-react-instructions"><?php echo esc_textarea( $integration_react ); ?></textarea>
			<p id="formstead-react-instructions"><?php echo esc_html__( 'Save as components/FormsteadForm.tsx. Import FormsteadForm from that file and render <FormsteadForm /> in your React or Next.js page. Keep the use client directive for Next.js. Each instance receives unique IDs automatically. Confirmation and errors appear on the page; without JavaScript the form redirects to the saved Success URL.', 'formstead' ); ?></p>
		</div>
		<p id="formstead-copy-controls" hidden><button id="formstead-copy-code" type="button" class="button" data-copy-success="<?php echo esc_attr__( 'Copied.', 'formstead' ); ?>" data-copy-fallback="<?php echo esc_attr__( 'Press Ctrl+C or Cmd+C to copy.', 'formstead' ); ?>"><?php echo esc_html__( 'Copy code', 'formstead' ); ?></button></p>
		<p id="formstead-copy-status" role="status" aria-atomic="true"></p>
		<p><?php echo esc_html__( 'For another site, add its origin (scheme, host and port, without a path) to Allowed origins and save. An empty list permits only this WordPress site. Serve local previews over HTTP rather than opening a file directly.', 'formstead' ); ?></p>
		<p><?php echo esc_html__( 'After changing fields, save and replace the code on your frontend. For HTML or JavaScript copies on the same page, give each copy a different ID prefix, including its labels and descriptions.', 'formstead' ); ?></p>
	<?php endif; ?>
</section>
