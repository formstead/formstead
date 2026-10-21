import { createHmac, timingSafeEqual } from 'node:crypto';

const TOLERANCE_SECONDS = 5 * 60;

export interface WebhookRequest {
	id: string;
	timestamp: string;
	signature: string;
	body: string;
	secret: string;
	now?: number;
}

/**
 * Whether this request matches a Standard Webhooks v1 signature.
 *
 * The timestamp has to be within five minutes of `now`. Any `v1` signature
 * in the header may match. The comparison does not stop at the first byte.
 */
export function verifyWebhook( request: WebhookRequest ): boolean {
	const now = request.now ?? Math.floor( Date.now() / 1000 );
	if ( ! timestampIsFresh( request.timestamp, now ) ) {
		return false;
	}

	const key = signingKey( request.secret );
	if ( key === null ) {
		return false;
	}

	const expected = createHmac( 'sha256', key )
		.update( `${ request.id }.${ request.timestamp }.${ request.body }` )
		.digest();

	return request.signature.split( ' ' ).some( ( part ) => signaturesMatch( part, expected ) );
}

function timestampIsFresh( timestamp: string, now: number ): boolean {
	if ( ! /^\d{1,12}$/.test( timestamp ) ) {
		return false;
	}

	const parsed = Number( timestamp );
	return Math.abs( now - parsed ) <= TOLERANCE_SECONDS;
}

function signingKey( secret: string ): Buffer | null {
	if ( ! secret.startsWith( 'whsec_' ) ) {
		return null;
	}

	const key = Buffer.from( secret.slice( 'whsec_'.length ), 'base64' );
	if ( key.length === 0 ) {
		return null;
	}

	return key;
}

function signaturesMatch( part: string, expected: Buffer ): boolean {
	if ( ! part.startsWith( 'v1,' ) ) {
		return false;
	}

	const given = Buffer.from( part.slice( 'v1,'.length ), 'base64' );
	if ( given.length !== expected.length ) {
		return false;
	}

	return timingSafeEqual( given, expected );
}
