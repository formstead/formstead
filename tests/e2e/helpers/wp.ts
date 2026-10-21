import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const jsonPrefix = 'FORMSTEAD_E2E_JSON ';

export interface DeliveryState {
	status: string;
	attempts: number;
	last_error: string;
}

export interface SubmissionState {
	receipt_id: string;
	status: string;
	deliveries: DeliveryState[];
}

export interface FormState {
	found: boolean;
	public_id?: string;
	honeypot_rejections?: number;
	submissions?: number;
	latest?: SubmissionState | null;
}

export interface E2EForm {
	public_id: string;
	endpoint: string;
	success_url: string;
	page_url?: string;
}

export interface E2EForms {
	base_url: string;
	forms: {
		same: E2EForm;
		cross: E2EForm;
		closed: E2EForm;
		rate: E2EForm;
	};
}

/**
 * Forms written by `npm run e2e:setup`.
 */
export function readForms(): E2EForms {
	return JSON.parse( readFileSync( 'tests/e2e/.auth/forms.json', 'utf8' ) ) as E2EForms;
}

/**
 * Run WP-CLI in the wp-env tests site.
 *
 * @param args Arguments after `wp`.
 */
export function wp( args: string[] ): string {
	return execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', '--env-cwd=wp-content/plugins/formstead', 'wp', ...args ],
		{ encoding: 'utf8' }
	);
}

/**
 * Read the JSON line printed by an end-to-end fixture.
 *
 * @param args Arguments after `wp`.
 */
export function wpJson( args: string[] ): unknown {
	const output = wp( args );
	const line = output
		.split( '\n' )
		.map( ( item ) => item.trim() )
		.find( ( item ) => item.startsWith( jsonPrefix ) );

	if ( ! line ) {
		throw new Error( `Missing ${ jsonPrefix.trim() } in wp output:\n${ output }` );
	}

	return JSON.parse( line.slice( jsonPrefix.length ) );
}

/**
 * Counts and the newest submission for a seeded form. No field values.
 *
 * @param publicId Seeded public id.
 */
export function formState( publicId: string ): FormState {
	return wpJson( [ 'eval-file', 'tests/e2e/fixtures/state.php', 'form', publicId ] ) as FormState;
}

/**
 * One submission and its deliveries. No field values.
 *
 * @param receiptId Public receipt id.
 */
export function receiptState( receiptId: string ): SubmissionState & { found: boolean } {
	return wpJson( [
		'eval-file',
		'tests/e2e/fixtures/state.php',
		'receipt',
		receiptId,
	] ) as SubmissionState & { found: boolean };
}
