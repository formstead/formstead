const fieldMessages: Record<string, string> = {
	required: 'This field is required.',
	too_long: 'This value is too long.',
	invalid_email: 'Enter a valid email address.',
	invalid_number: 'Enter a valid number.',
	too_small: 'This value is too small.',
	too_large: 'This value is too large.',
	too_precise: 'This value has too many decimal places.',
	invalid_choice: 'Choose a valid option.',
	invalid: 'This value is not valid.',
};

type ErrorPayload = {
	error?: {
		code?: string;
		message?: string;
		fields?: Record<string, string[]>;
	};
};

type SuccessPayload = {
	receipt_id?: string;
};

/**
 * Post the form with fetch while keeping a native submit as the fallback.
 *
 * A new idempotency key is created after success and after a 409. A network
 * failure or a 5xx keeps the key so the next try is the same submission.
 * `_fs_elapsed` is the whole milliseconds since this script loaded.
 */
export function enhanceForm(form: HTMLFormElement): void {
	const status = document.getElementById('formstead-status');
	const started = performance.now();
	let idempotencyKey = crypto.randomUUID();

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		void submitForm(form, status, started, () => idempotencyKey, (next) => {
			idempotencyKey = next;
		});
	});
}

async function submitForm(
	form: HTMLFormElement,
	status: HTMLElement | null,
	started: number,
	currentKey: () => string,
	setKey: (key: string) => void,
): Promise<void> {
	clearErrors(form);
	const button = form.querySelector('button[type="submit"]');
	if (button instanceof HTMLButtonElement) {
		button.disabled = true;
	}

	try {
		const response = await fetch(form.action, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'Idempotency-Key': currentKey(),
			},
			body: JSON.stringify(payload(form, started)),
		});

		if (response.status === 201) {
			const body = (await response.json()) as SuccessPayload;
			const receipt = typeof body.receipt_id === 'string' ? body.receipt_id : '';
			setStatus(status, receipt === '' ? 'Thanks.' : `Thanks. Receipt ${receipt}.`);
			setKey(crypto.randomUUID());
			return;
		}

		if (response.status === 422) {
			const body = (await response.json()) as ErrorPayload;
			const invalid = showFieldErrors(form, body.error?.fields ?? {});
			setStatus(status, 'Check the form and try again.');
			invalid?.focus();
			return;
		}

		if (response.status === 429) {
			const retry = response.headers.get('Retry-After');
			setStatus(
				status,
				retry === null
					? 'Too many submissions. Try again later.'
					: `Too many submissions. Try again in ${retry} seconds.`,
			);
			return;
		}

		if (response.status === 409) {
			setStatus(status, 'This submission was already sent with different details. Send it again.');
			setKey(crypto.randomUUID());
			return;
		}

		setStatus(status, 'The form could not be sent. Try again.');
	} catch {
		setStatus(status, 'The form could not be sent. Try again.');
	} finally {
		if (button instanceof HTMLButtonElement) {
			button.disabled = false;
		}
	}
}

function payload(form: HTMLFormElement, started: number): Record<string, string | number> {
	const body: Record<string, string | number> = {
		email: value(form, 'email'),
		message: value(form, 'message'),
		_fs_elapsed: Math.max(0, Math.round(performance.now() - started)),
	};
	const honeypot = value(form, '_fs_honeypot').trim();
	if (honeypot !== '') {
		body._fs_honeypot = honeypot;
	}
	return body;
}

function value(form: HTMLFormElement, name: string): string {
	const element = form.elements.namedItem(name);
	if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
		return element.value;
	}
	return '';
}

function clearErrors(form: HTMLFormElement): void {
	form.querySelectorAll('[aria-invalid]').forEach((field) => {
		field.removeAttribute('aria-invalid');
	});
	form.querySelectorAll('[id$="-error"]').forEach((node) => {
		node.textContent = '';
	});
}

function showFieldErrors(form: HTMLFormElement, fields: Record<string, string[]>): HTMLElement | null {
	let first: HTMLElement | null = null;
	for (const field of form.querySelectorAll('input, textarea')) {
		if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
			continue;
		}
		const codes = fields[field.name];
		if (!codes || codes.length === 0) {
			continue;
		}
		const code = codes[0] ?? 'invalid';
		field.setAttribute('aria-invalid', 'true');
		const error = document.getElementById(`${field.id}-error`);
		if (error) {
			error.textContent = fieldMessages[code] ?? 'This value is not valid.';
		}
		first ??= field;
	}
	return first;
}

function setStatus(status: HTMLElement | null, message: string): void {
	if (status) {
		status.textContent = message;
	}
}
