import { NextResponse } from 'next/server';
import { verifyWebhook } from '../../../lib/verify';

interface WebhookBody {
	receipt_id?: unknown;
}

/**
 * Accept a Formstead webhook after the signature checks.
 *
 * When the API user, password, and base URL are set, the route then reads
 * the submission. The response from that read is not required to accept the
 * webhook: a missing password still returns 204 after a valid signature.
 */
export async function POST( request: Request ): Promise<NextResponse> {
	const body = await request.text();
	const secret = process.env.FORMSTEAD_WEBHOOK_SECRET ?? '';
	const valid = verifyWebhook( {
		id: request.headers.get( 'webhook-id' ) ?? '',
		timestamp: request.headers.get( 'webhook-timestamp' ) ?? '',
		signature: request.headers.get( 'webhook-signature' ) ?? '',
		body,
		secret,
	} );
	if ( ! valid ) {
		return NextResponse.json(
			{ error: { code: 'invalid_signature', message: 'The signature is not valid.' } },
			{ status: 401 },
		);
	}

	const receipt = receiptId( body );
	const loaded = await loadSubmission( receipt );
	if ( loaded === 'skipped' ) {
		return new NextResponse( null, { status: 204 } );
	}

	if ( loaded === 'failed' ) {
		return NextResponse.json(
			{ error: { code: 'upstream', message: 'The submission could not be read.' } },
			{ status: 502 },
		);
	}

	return NextResponse.json( loaded, { status: 200 } );
}

function receiptId( body: string ): string {
	try {
		const parsed = JSON.parse( body ) as WebhookBody;
		return typeof parsed.receipt_id === 'string' ? parsed.receipt_id : '';
	} catch {
		return '';
	}
}

async function loadSubmission( receipt: string ): Promise<'skipped' | 'failed' | unknown> {
	const base = process.env.FORMSTEAD_API_BASE ?? '';
	const user = process.env.FORMSTEAD_API_USER ?? '';
	const password = process.env.FORMSTEAD_API_PASSWORD ?? '';
	if ( base === '' || user === '' || password === '' || receipt === '' ) {
		return 'skipped';
	}

	const response = await fetch( `${ base.replace( /\/$/, '' ) }/submissions/${ receipt }`, {
		headers: {
			Accept: 'application/json',
			Authorization: `Basic ${ Buffer.from( `${ user }:${ password }` ).toString( 'base64' ) }`,
		},
	} );
	if ( ! response.ok ) {
		return 'failed';
	}

	return response.json() as Promise<unknown>;
}
