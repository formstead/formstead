'use client';

import { useId, useRef, useState, type FormEvent } from 'react';

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
const config = {
  "endpoint": "",
  "prefix": "formstead",
  "fields": [
    {
      "name": "email",
      "label": "Email",
      "type": "email",
      "required": true,
      "max_length": 255
    },
    {
      "name": "message",
      "label": "Message",
      "type": "textarea",
      "required": true,
      "max_length": 2000
    }
  ],
  "send": "Send",
  "company": "Company",
  "choose": "Choose an option",
  "precision": "Use no more than %s decimal places."
};

const messages: Record<string, string> = {
  "already": "These details were already accepted. They have not been sent again.",
  "accepted": "Thanks. Your submission was accepted.",
  "check": "Check the form and try again.",
  "unconfirmed": "We could not confirm whether your submission was accepted. Try again with the same details.",
  "failed": "The form could not be sent. Check your details and try again.",
  "unavailable": "This form is unavailable. Please contact the site owner.",
  "conflict": "This submission may already have been sent with different details. Check before sending again.",
  "rate": "Too many submissions. Try again later.",
  "retry": "Too many submissions. Try again in %s seconds.",
  "required": "This field is required.",
  "too_long": "This value is too long.",
  "invalid_email": "Enter a valid email address.",
  "invalid_number": "Enter a valid number.",
  "too_small": "This value is too small.",
  "too_large": "This value is too large.",
  "too_precise": "This value has too many decimal places.",
  "invalid_choice": "Choose a valid option.",
  "invalid": "This value is not valid."
};
const fields: Field[] = config.fields;

export function ContactForm({ action }: { action: string }) {
	const instance = useId();
	const prefix = `${config.prefix}-${instance}`;
	const sending = useRef(false);
	const key = useRef<string | null>(null);
	const acceptedBody = useRef<string | null>(null);
	const retryAt = useRef(0);
	const [busy, setBusy] = useState(false);
	const [status, setStatus] = useState('');
	const [errors, setErrors] = useState<Record<string, string>>({});

	async function onSubmit(event: FormEvent<HTMLFormElement>) {
		// Keep native POST when the browser cannot enhance the form.
		if (!window.fetch || !window.FormData || !window.crypto ||
			(typeof crypto.randomUUID !== 'function' && typeof crypto.getRandomValues !== 'function')) {
			return;
		}
		event.preventDefault();
		if (sending.current) return;
		if (Date.now() < retryAt.current) {
			setStatus(messages.retry.replace('%s', String(Math.ceil((retryAt.current - Date.now()) / 1000))));
			return;
		}
		const form = event.currentTarget;
		const body = new FormData(form);
		const signature = JSON.stringify(Array.from(body.entries()));
		if (signature === acceptedBody.current) {
			setStatus(messages.already);
			return;
		}
		sending.current = true;
		setBusy(true);
		setErrors({});
		setStatus('');
		try {
			key.current = key.current || (typeof crypto.randomUUID === 'function' ? crypto.randomUUID() :
				Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join(''));
			const response = await fetch(form.getAttribute('action')!, {
				method: 'POST', credentials: 'omit',
				headers: { Accept: 'application/json', 'Idempotency-Key': key.current },
				body,
			});
			if (response.status === 201) {
				const result = await response.json();
				if (!result || typeof result.receipt_id !== 'string' || !/^fsr_[a-z2-7]{16}$/.test(result.receipt_id)) {
					throw new Error('Unconfirmed response');
				}
				acceptedBody.current = signature;
				key.current = null;
				setStatus(messages.accepted);
			} else if (response.status === 422) {
				const result = await response.json();
				const invalid = result && result.error && result.error.fields;
				if (!invalid || typeof invalid !== 'object' || Array.isArray(invalid)) {
					throw new Error('Unconfirmed response');
				}
				key.current = null;
				const next: Record<string, string> = {};
				let first: HTMLElement | null = null;
				fields.forEach(field => {
					const codes = invalid[field.name];
					if (!Array.isArray(codes) || codes.length === 0) return;
					const message = messages[codes[0]];
					next[field.name] = typeof message === 'string' ? message : messages.invalid;
					first = first || form.querySelector<HTMLElement>(`[data-formstead-field="${field.name}"]`);
				});
				setErrors(next);
				setStatus(messages.check);
				(first as HTMLElement | null)?.focus();
			} else if (response.status === 429) {
				const retry = response.headers.get('Retry-After');
				const seconds = retry !== null && /^\d+$/.test(retry) ? Number(retry) : NaN;
				const deadline = Date.now() + seconds * 1000;
				if (Number.isSafeInteger(seconds) && Number.isSafeInteger(deadline)) {
					retryAt.current = deadline;
					setStatus(messages.retry.replace('%s', String(seconds)));
				} else {
					setStatus(messages.rate);
				}
			} else if (response.status === 409) {
				key.current = null;
				setStatus(messages.conflict);
			} else if (response.status === 403 || response.status === 404) {
				setStatus(messages.unavailable);
			} else {
				setStatus(response.status >= 500 ? messages.unconfirmed : messages.failed);
			}
		} catch {
			setStatus(messages.unconfirmed);
		} finally {
			sending.current = false;
			setBusy(false);
		}
	}

	return (
		<form id={prefix} className="formstead-form" action={action} method="post" onSubmit={onSubmit}>
			{fields.map(field => {
				const id = `${prefix}-${field.name}`;
				const common = {
					id, name: field.name, required: field.required,
					'data-formstead-field': field.name,
					'aria-invalid': errors[field.name] ? true as const : undefined,
					'aria-describedby': `${field.type === 'number' ? `${id}-hint ` : ''}${id}-error`,
				};
				return (
					<div className="formstead-field" key={field.name}>
						<label htmlFor={id}>{field.label || field.name}</label>
						{field.type === 'textarea' ? (
							<textarea {...common} maxLength={field.max_length} />
						) : field.type === 'choice' ? (
							<select {...common} name={field.name + (field.multiple ? '[]' : '')} multiple={field.multiple}>
								{!field.multiple && <option value="">{config.choose}</option>}
								{(field.options ?? []).map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
							</select>
						) : field.type === 'number' ? (
							<>
								<input {...common} type="number" step="any" min={field.min} max={field.max} />
								<p id={`${id}-hint`}>{config.precision.replace('%s', String(field.scale))}</p>
							</>
						) : field.type === 'boolean' ? (
							<input {...common} type="checkbox" value="1" />
						) : (
							<input {...common} type={field.type} maxLength={field.max_length} autoComplete={field.type === 'email' ? 'email' : undefined} />
						)}
						<p id={`${id}-error`} data-formstead-error={field.name} hidden={!errors[field.name]}>{errors[field.name] || ''}</p>
					</div>
				);
			})}
			<div aria-hidden="true" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clipPath: 'inset(50%)', whiteSpace: 'nowrap' }}>
				<label htmlFor={`${prefix}-_fs_honeypot`}>{config.company}</label>
				<input id={`${prefix}-_fs_honeypot`} name="_fs_honeypot" type="text" defaultValue="" tabIndex={-1} autoComplete="off" />
			</div>
			<button type="submit" disabled={busy}>{config.send}</button>
			<p role="status" aria-atomic="true">{status}</p>
		</form>
	);
}
