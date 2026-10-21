import { randomUUID } from 'node:crypto';
import { NextResponse } from 'next/server';

interface SubmissionBody {
	email?: unknown;
	message?: unknown;
	_fs_elapsed?: unknown;
	_fs_honeypot?: unknown;
}

/**
 * Send one submission to Formstead from the server.
 *
 * Node does not add an Origin header. A missing Origin is allowed, so this
 * route does not need to be on the form's allowlist.
 */
export async function POST( request: Request ): Promise<NextResponse> {
	const endpoint = process.env.FORMSTEAD_ENDPOINT;
	if ( ! endpoint ) {
		return NextResponse.json(
			{ error: { code: 'not_configured', message: 'Set FORMSTEAD_ENDPOINT.' } },
			{ status: 500 },
		);
	}

	const submitted = await readSubmission( request );
	const idempotencyKey = request.headers.get( 'idempotency-key' ) ?? randomUUID();
	const upstream = await fetch( endpoint, {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'Idempotency-Key': idempotencyKey,
		},
		body: JSON.stringify( submitted ),
		redirect: 'manual',
	} );

	if ( upstream.status === 201 || upstream.status === 422 || upstream.status === 409 ) {
		return NextResponse.json( await upstream.json(), { status: upstream.status } );
	}

	if ( upstream.status === 429 ) {
		const headers = new Headers();
		const retry = upstream.headers.get( 'Retry-After' );
		if ( retry !== null ) {
			headers.set( 'Retry-After', retry );
		}

		return NextResponse.json( await upstream.json(), { status: 429, headers } );
	}

	return NextResponse.json(
		{ error: { code: 'upstream', message: 'The form could not be sent.' } },
		{ status: 502 },
	);
}

async function readSubmission( request: Request ): Promise<Record<string, string | number>> {
	const type = request.headers.get( 'content-type' ) ?? '';
	const parsed: SubmissionBody = type.includes( 'application/json' )
		? ( await request.json() ) as SubmissionBody
		: await fieldsFromForm( request );

	const body: Record<string, string | number> = {
		email: text( parsed.email ),
		message: text( parsed.message ),
		_fs_elapsed: elapsed( parsed._fs_elapsed ),
	};
	const honeypot = text( parsed._fs_honeypot ).trim();
	if ( honeypot !== '' ) {
		body._fs_honeypot = honeypot;
	}

	return body;
}

async function fieldsFromForm( request: Request ): Promise<SubmissionBody> {
	const form = await request.formData();
	return {
		email: form.get( 'email' ),
		message: form.get( 'message' ),
		_fs_elapsed: form.get( '_fs_elapsed' ),
		_fs_honeypot: form.get( '_fs_honeypot' ),
	};
}

function text( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

function elapsed( value: unknown ): number {
	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return Math.max( 0, Math.round( value ) );
	}

	if ( typeof value === 'string' && /^\d{1,12}$/.test( value ) ) {
		return Number( value );
	}

	return 5000;
}
