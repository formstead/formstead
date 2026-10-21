'use client';

import { useState, type FormEvent } from 'react';

const fieldMessages: Record<string, string> = {
	required: 'This field is required.',
	invalid_email: 'Enter a valid email address.',
	too_long: 'This value is too long.',
	invalid: 'This value is not valid.',
};

interface ErrorPayload {
	error?: {
		fields?: Record<string, string[]>;
	};
}

interface SuccessPayload {
	receipt_id?: string;
}

/**
 * Post the form with fetch.
 *
 * `action` is either the Formstead URL, which the browser calls directly, or
 * `/api/contact`, which the route handler calls without an Origin header.
 */
export function ContactForm( { action }: { action: string } ) {
	const [ status, setStatus ] = useState( '' );
	const [ started ] = useState( () => performance.now() );

	async function onSubmit( event: FormEvent<HTMLFormElement> ) {
		event.preventDefault();
		const form = event.currentTarget;
		setStatus( '' );
		const response = await fetch( action, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'Idempotency-Key': crypto.randomUUID(),
			},
			body: JSON.stringify( {
				email: value( form, 'email' ),
				message: value( form, 'message' ),
				_fs_elapsed: Math.max( 0, Math.round( performance.now() - started ) ),
			} ),
		} );

		if ( response.status === 201 ) {
			const body = ( await response.json() ) as SuccessPayload;
			const receipt = typeof body.receipt_id === 'string' ? body.receipt_id : '';
			setStatus( receipt === '' ? 'Thanks.' : `Thanks. Receipt ${ receipt }.` );
			return;
		}

		if ( response.status === 422 ) {
			const body = ( await response.json() ) as ErrorPayload;
			const code = body.error?.fields?.message?.[ 0 ] ?? body.error?.fields?.email?.[ 0 ] ?? 'invalid';
			setStatus( fieldMessages[ code ] ?? 'Check the form and try again.' );
			return;
		}

		if ( response.status === 409 ) {
			setStatus( 'This submission was already sent with different details. Send it again.' );
			return;
		}

		if ( response.status === 429 ) {
			const retry = response.headers.get( 'Retry-After' );
			setStatus( retry === null ? 'Too many submissions. Try again later.' : `Too many submissions. Try again in ${ retry } seconds.` );
			return;
		}

		setStatus( 'The form could not be sent. Try again.' );
	}

	return (
		<form onSubmit={ onSubmit }>
			<div>
				<label htmlFor="formstead-email">Email</label>
				<br />
				<input id="formstead-email" name="email" type="email" autoComplete="email" />
			</div>
			<div>
				<label htmlFor="formstead-message">Message</label>
				<br />
				<textarea id="formstead-message" name="message" />
			</div>
			<button type="submit">Send</button>
			<p role="status">{ status }</p>
		</form>
	);
}

function value( form: HTMLFormElement, name: string ): string {
	const element = form.elements.namedItem( name );
	if ( element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement ) {
		return element.value;
	}

	return '';
}
