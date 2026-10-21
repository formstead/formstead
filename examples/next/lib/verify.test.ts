import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { verifyWebhook } from './verify.ts';

interface Vector {
	id: string;
	timestamp: number;
	secret: string;
	body: string;
	signature: string;
}

const vector = JSON.parse(
	readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', '..', '..', 'tests', 'fixtures', 'webhook-vector.json' ), 'utf8' ),
) as Vector;

describe( 'verifyWebhook', () => {
	it( 'accepts the shared Standard Webhooks vector', () => {
		assert.equal(
			verifyWebhook( {
				id: vector.id,
				timestamp: String( vector.timestamp ),
				signature: vector.signature,
				body: vector.body,
				secret: vector.secret,
				now: vector.timestamp,
			} ),
			true,
		);
	} );

	it( 'rejects a body that was changed after signing', () => {
		assert.equal(
			verifyWebhook( {
				id: vector.id,
				timestamp: String( vector.timestamp ),
				signature: vector.signature,
				body: vector.body.replace( 'Contact', 'Other' ),
				secret: vector.secret,
				now: vector.timestamp,
			} ),
			false,
		);
	} );

	it( 'rejects a timestamp outside five minutes', () => {
		assert.equal(
			verifyWebhook( {
				id: vector.id,
				timestamp: String( vector.timestamp ),
				signature: vector.signature,
				body: vector.body,
				secret: vector.secret,
				now: vector.timestamp + ( 5 * 60 ) + 1,
			} ),
			false,
		);
	} );
} );
