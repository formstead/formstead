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

interface SendState {
	key: string | null;
	elapsed: number | null;
	busy: boolean;
	retryAt: number;
	accepted: string | null;
}

/** Enhance the fixed contact demo; timing is frozen for each logical submission. */
export function enhanceForm(form: HTMLFormElement): void {
	if (!window.fetch || !window.FormData || !window.crypto ||
		(typeof crypto.randomUUID !== 'function' && typeof crypto.getRandomValues !== 'function')) return;
	const status = document.getElementById('formstead-status');
	const started = performance.now();
	const state: SendState = { key: null, elapsed: null, busy: false, retryAt: 0, accepted: null };
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		if (state.busy) return;
		if (Date.now() < state.retryAt) {
			setStatus(status, `Too many submissions. Try again in ${Math.ceil((state.retryAt - Date.now()) / 1000)} seconds.`);
			return;
		}
		const signature = JSON.stringify(Array.from(new FormData(form).entries()));
		if (signature === state.accepted) {
			setStatus(status, 'These details were already accepted. They have not been sent again.');
			return;
		}
		state.busy = true;
		void submitForm(form, status, started, state, signature);
	});
}

async function submitForm(form: HTMLFormElement, status: HTMLElement | null, started: number, state: SendState, signature: string): Promise<void> {
	clearErrors(form);
	setStatus(status, '');
	const button = form.querySelector('button[type="submit"]');
	if (button instanceof HTMLButtonElement) button.disabled = true;
	try {
		state.key ??= typeof crypto.randomUUID === 'function' ? crypto.randomUUID() :
			Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
		state.elapsed ??= Math.max(0, Math.round(performance.now() - started));
		const response = await fetch(form.getAttribute('action')!, {
			method: 'POST', credentials: 'omit',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': state.key },
			body: JSON.stringify(payload(form, state.elapsed)),
		});
		if (response.status === 201) {
			const body = (await response.json()) as SuccessPayload;
			if (typeof body?.receipt_id !== 'string' || !/^fsr_[a-z2-7]{16}$/.test(body.receipt_id)) throw new Error('Unconfirmed response');
			state.accepted = signature;
			state.key = null;
			state.elapsed = null;
			setStatus(status, `Thanks. Receipt ${body.receipt_id}.`);
		} else if (response.status === 422) {
			const body = (await response.json()) as ErrorPayload;
			const fields = body?.error?.fields;
			if (!fields || typeof fields !== 'object' || Array.isArray(fields)) throw new Error('Unconfirmed response');
			state.key = null;
			state.elapsed = null;
			const invalid = showFieldErrors(form, fields);
			setStatus(status, 'Check the form and try again.');
			invalid?.focus();
		} else if (response.status === 429) {
			const retry = response.headers.get('Retry-After');
			const seconds = retry !== null && /^\d+$/.test(retry) ? Number(retry) : NaN;
			const deadline = Date.now() + seconds * 1000;
			if (Number.isSafeInteger(seconds) && Number.isSafeInteger(deadline)) {
				state.retryAt = deadline;
				setStatus(status, `Too many submissions. Try again in ${seconds} seconds.`);
			} else setStatus(status, 'Too many submissions. Try again later.');
		} else if (response.status === 409) {
			state.key = null;
			state.elapsed = null;
			setStatus(status, 'This submission may already have been sent with different details. Check before sending again.');
		} else if (response.status === 403 || response.status === 404) {
			setStatus(status, 'This form is unavailable. Please contact the site owner.');
		} else {
			setStatus(status, response.status >= 500 ? unconfirmed : 'The form could not be sent. Check your details and try again.');
		}
	} catch {
		setStatus(status, unconfirmed);
	} finally {
		state.busy = false;
		if (button instanceof HTMLButtonElement) button.disabled = false;
	}
}

const unconfirmed = 'We could not confirm whether your submission was accepted. Try again with the same details.';

function payload(form: HTMLFormElement, elapsed: number): Record<string, string | number> {
	const body: Record<string, string | number> = { email: value(form, 'email'), message: value(form, 'message'), _fs_elapsed: elapsed };
	const honeypot = value(form, '_fs_honeypot').trim();
	if (honeypot !== '') body._fs_honeypot = honeypot;
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
			const message = fieldMessages[code];
			error.textContent = typeof message === 'string' ? message : 'This value is not valid.';
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
