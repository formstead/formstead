<?php
/**
 * Standalone progressively enhanced HTML and inline JavaScript.
 *
 * @package Formstead
 * @var \Formstead\Forms\Form $form Persisted form.
 * @var string $endpoint Intake URL.
 * @var string $prefix Instance ID prefix.
 * @var bool $enhanced Whether to render accessible error/status controls.
 */

defined( 'ABSPATH' ) || exit;

require __DIR__ . '/messages.php';
require __DIR__ . '/html.php';
?>
<script>
(function () {
	const form = document.currentScript.previousElementSibling;
	if (!(form instanceof HTMLFormElement) || !window.fetch || !window.FormData || !window.crypto ||
		(typeof crypto.randomUUID !== 'function' && typeof crypto.getRandomValues !== 'function')) {
		return;
	}
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX flags protect the inline script context. ?>
	const messages = <?php echo wp_json_encode( $messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
	const status = form.querySelector('[data-formstead-status]');
	const button = form.querySelector('button[type="submit"]');
	const fields = Array.from(form.querySelectorAll('[data-formstead-field]'));
	let submitting = false;
	let key = null;
	let acceptedBody = null;
	let retryAt = 0;

	function newKey() {
		if (typeof crypto.randomUUID === 'function') {
			return crypto.randomUUID();
		}
		return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
	}

	function clearErrors() {
		fields.forEach(field => field.removeAttribute('aria-invalid'));
		form.querySelectorAll('[data-formstead-error]').forEach(error => {
			error.textContent = '';
			error.hidden = true;
		});
	}

	function showErrors(errors) {
		let first = null;
		fields.forEach(field => {
			const name = field.getAttribute('data-formstead-field');
			const codes = errors[name];
			if (!Array.isArray(codes) || codes.length === 0) {
				return;
			}
			const message = messages[codes[0]];
			const error = form.querySelector('[data-formstead-error="' + name + '"]');
			field.setAttribute('aria-invalid', 'true');
			error.textContent = typeof message === 'string' ? message : messages.invalid;
			error.hidden = false;
			first = first || field;
		});
		if (first) {
			first.focus();
		}
	}

	form.addEventListener('submit', async event => {
		event.preventDefault();
		if (submitting) {
			return;
		}
		if (Date.now() < retryAt) {
			status.textContent = messages.retry.replace('%s', String(Math.ceil((retryAt - Date.now()) / 1000)));
			return;
		}
		const body = new FormData(form);
		const signature = JSON.stringify(Array.from(body.entries()));
		if (signature === acceptedBody) {
			status.textContent = messages.already;
			return;
		}
		submitting = true;
		button.disabled = true;
		clearErrors();
		status.textContent = '';
		try {
			key = key || newKey();
			const response = await fetch(form.getAttribute('action'), {
				method: 'POST',
				credentials: 'omit',
				headers: { Accept: 'application/json', 'Idempotency-Key': key },
				body,
			});
			if (response.status === 201) {
				const result = await response.json();
				if (!result || typeof result.receipt_id !== 'string' || !/^fsr_[a-z2-7]{16}$/.test(result.receipt_id)) {
					throw new Error('Unconfirmed response');
				}
				acceptedBody = signature;
				key = null;
				status.textContent = messages.accepted;
			} else if (response.status === 422) {
				const result = await response.json();
				const errors = result && result.error && result.error.fields;
				if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
					throw new Error('Unconfirmed response');
				}
				key = null;
				showErrors(errors);
				status.textContent = messages.check;
			} else if (response.status === 429) {
				const retry = response.headers.get('Retry-After');
				const seconds = retry !== null && /^\d+$/.test(retry) ? Number(retry) : NaN;
				const deadline = Date.now() + seconds * 1000;
				if (Number.isSafeInteger(seconds) && Number.isSafeInteger(deadline)) {
					retryAt = deadline;
					status.textContent = messages.retry.replace('%s', String(seconds));
				} else {
					status.textContent = messages.rate;
				}
			} else if (response.status === 409) {
				key = null;
				status.textContent = messages.conflict;
			} else if (response.status === 403 || response.status === 404) {
				status.textContent = messages.unavailable;
			} else {
				status.textContent = response.status >= 500 ? messages.unconfirmed : messages.failed;
			}
		} catch {
			status.textContent = messages.unconfirmed;
		} finally {
			submitting = false;
			button.disabled = false;
		}
	});
})();
</script>
