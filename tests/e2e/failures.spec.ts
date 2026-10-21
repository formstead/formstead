import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { basename, dirname, resolve as resolvePath } from 'node:path';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { formState, readForms, wp } from './helpers/wp';

const forms = readForms();
const htmlExamples = 'http://localhost:4400';
const astro = 'http://localhost:4321';
const mailPort = process.env.FORMSTEAD_MAILPIT_UI_PORT ?? '8025';

test.beforeEach( () => {
	clearFault();
} );

test( 'a storage fault stores nothing', async ( { browser } ) => {
	const stamp = Date.now();
	const email = `storage-${ stamp }@example.com`;
	const message = `Storage fault ${ stamp }`;
	const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
	setFault( 'storage' );

	try {
		const native = await openPublic( browser );
		await native.goto( `${ htmlExamples }/cross-origin.html` );
		await fill( native, email, message );
		await send( native );
		await expect( native.getByRole( 'heading', { name: 'Something went wrong' } ) ).toBeVisible();
		await expect( native.locator( 'body' ) ).not.toContainText( email );
		await expect( native.locator( 'body' ) ).not.toContainText( message );
		await native.context().close();

		const fetched = await openPublic( browser );
		await fetched.goto( `${ astro }/fetch/` );
		await fill( fetched, `storage-fetch-${ stamp }@example.com`, `Storage fetch ${ stamp }` );
		await send( fetched );
		await expect( fetched.getByRole( 'status' ) ).toHaveText( 'The form could not be sent. Try again.' );
		await fetched.context().close();
	} finally {
		clearFault();
	}

	const after = formState( forms.forms.cross.public_id );
	expect( after.submissions ).toBe( before );
} );

test( 'a mail fault keeps the submission and is visible in the admin', async ( { browser, page } ) => {
	const stamp = Date.now();
	const email = `mail-fault-${ stamp }@example.com`;
	const message = `Mail fault ${ stamp }`;
	const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
	setFault( 'mail' );

	try {
		const formPage = await openPublic( browser );
		await formPage.clock.install();
		await formPage.clock.pauseAt( Date.now() );
		await formPage.goto( `${ astro }/fetch/` );
		await fill( formPage, email, message );
		await formPage.clock.fastForward( 4000 );
		await send( formPage );
		await expect( formPage.getByRole( 'status' ) ).toContainText( /Thanks\. Receipt fsr_[a-z2-7]{16}\./ );
		await formPage.context().close();

		wp( [ 'formstead', 'deliveries', 'run' ] );
		const stored = formState( forms.forms.cross.public_id );
		expect( stored.submissions ).toBe( before + 1 );
		expect( stored.latest?.status ).toBe( 'new' );
		expect( stored.latest?.deliveries?.[ 0 ]?.status ).toBe( 'pending' );
		expect( stored.latest?.deliveries?.[ 0 ]?.last_error ).toBe( 'mail_failed' );

		await page.goto( '/wp-admin/admin.php?page=formstead' );
		await page.getByRole( 'row', { name: email } ).getByRole( 'link' ).first().click();
		await expect( page.getByRole( 'cell', { name: 'mail_failed', exact: true } ) ).toBeVisible();
		const detail = page.url();

		clearFault();
		releaseMailRetries();
		await clearMailbox();
		wp( [ 'formstead', 'deliveries', 'run' ] );

		const sent = formState( forms.forms.cross.public_id );
		expect( sent.latest?.deliveries?.[ 0 ]?.status ).toBe( 'handed_off' );
		await page.goto( detail );
		await expect( page.getByRole( 'cell', { name: 'Handed to the mail system', exact: true } ) ).toBeVisible();
		await waitForOneMessage( [ email, message ] );
	} finally {
		clearFault();
	}
} );

test( 'a stopped database can be retried with the same key', async ( { browser } ) => {
	test.setTimeout( 120_000 );
	const stamp = Date.now();
	const email = `db-${ stamp }@example.com`;
	const message = `Database down ${ stamp }`;
	const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
	const container = testsMysql();
	const formPage = await openPublic( browser );

	try {
		await formPage.clock.install();
		await formPage.clock.pauseAt( Date.now() );
		await formPage.goto( `${ astro }/fetch/` );
		await fill( formPage, email, message );
		execFileSync( 'docker', [ 'stop', container ], { stdio: 'ignore' } );
		await waitForSite( false );
		await send( formPage );
		await expect( formPage.getByRole( 'status' ) ).toHaveText( 'The form could not be sent. Try again.' );

		execFileSync( 'docker', [ 'start', container ], { stdio: 'ignore' } );
		await waitForSite( true );
		await formPage.clock.fastForward( 4000 );
		await send( formPage );
		await expect( formPage.getByRole( 'status' ) ).toContainText( /Thanks\. Receipt fsr_[a-z2-7]{16}\./ );
	} finally {
		execFileSync( 'docker', [ 'start', container ], { stdio: 'ignore' } );
		await waitForSite( true );
		await formPage.context().close();
	}

	const after = formState( forms.forms.cross.public_id );
	expect( after.submissions ).toBe( before + 1 );
	expect( after.latest?.status ).toBe( 'new' );
} );

test( 'parallel requests with one key store one submission', async ( { page } ) => {
	const stamp = Date.now();
	const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
	const key = crypto.randomUUID();
	const body = JSON.stringify( {
		email: `parallel-${ stamp }@example.com`,
		message: `Parallel ${ stamp }`,
		_fs_elapsed: 5000,
	} );
	const responses = await postJsonFromBrowser(
		page,
		forms.forms.cross.endpoint,
		Array.from( { length: 5 }, () => body ),
		key
	);

	expect( responses.filter( ( response ) => response.status === 201 ) ).toHaveLength( 5 );
	expect( responses.filter( ( response ) => response.replayed !== 'true' ) ).toHaveLength( 1 );

	const after = formState( forms.forms.cross.public_id );
	expect( after.submissions ).toBe( before + 1 );
	expect( after.latest?.deliveries ?? [] ).toHaveLength( 1 );
} );

test( 'a form limited to 3 per minute accepts 3 of 6 parallel posts', async ( { page } ) => {
	const stamp = Date.now();
	const before = formState( forms.forms.rate.public_id ).submissions ?? 0;
	const responses = await postJsonFromBrowser(
		page,
		forms.forms.rate.endpoint,
		Array.from( { length: 6 }, ( _unused, index ) =>
			JSON.stringify( {
				email: `rate-${ stamp }-${ index }@example.com`,
				message: `Rate ${ stamp } ${ index }`,
				_fs_elapsed: 5000,
			} )
		)
	);

	expect( responses.filter( ( response ) => response.status === 201 ) ).toHaveLength( 3 );
	const limited = responses.filter( ( response ) => response.status === 429 );
	expect( limited ).toHaveLength( 3 );
	for ( const response of limited ) {
		expect( response.retryAfter ).toMatch( /^\d+$/ );
	}
	expect( formState( forms.forms.rate.public_id ).submissions ).toBe( before + 3 );
} );

async function openPublic( browser: Browser ): Promise<Page> {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	return context.newPage();
}

async function fill( page: Page, email: string, message: string ): Promise<void> {
	const form = page.locator( 'form' ).filter( { has: page.locator( '#formstead-honeypot' ) } );
	await form.locator( '#formstead-email' ).fill( email );
	await form.locator( '#formstead-message' ).fill( message );
}

async function send( page: Page ): Promise<void> {
	await page
		.locator( 'form' )
		.filter( { has: page.locator( '#formstead-honeypot' ) } )
		.getByRole( 'button', { name: 'Send' } )
		.click();
}

function setFault( value: 'storage' | 'mail' ): void {
	wp( [ 'eval', `update_option( "formstead_e2e_fault", "${ value }", false );` ] );
}

function clearFault(): void {
	wp( [ 'eval', 'delete_option( "formstead_e2e_fault" );' ] );
}

function releaseMailRetries(): void {
	wp( [
		'eval',
		'global $wpdb; $table = $wpdb->prefix . "formstead_deliveries"; $wpdb->query( "UPDATE {$table} SET next_attempt_at = UTC_TIMESTAMP() WHERE status = \'pending\' AND last_error = \'mail_failed\'" );',
	] );
}

function testsMysql(): string {
	const project = basename( wpEnvCacheDirectory() );
	const names = execFileSync(
		'docker',
		[
			'ps',
			'-a',
			'--filter',
			`label=com.docker.compose.project=${ project }`,
			'--filter',
			'label=com.docker.compose.service=tests-mysql',
			'--format',
			'{{.Names}}',
		],
		{ encoding: 'utf8' }
	)
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line !== '' );
	const name = names[ 0 ];
	if ( names.length !== 1 || ! name ) {
		throw new Error( `Expected one tests database for ${ project }, found ${ names.length }.` );
	}
	return name;
}

/**
 * Cache directory wp-env derives from this checkout's `.wp-env.json`.
 *
 * The compose project name is that directory's basename, so `docker stop`
 * cannot select another checkout's tests database.
 */
function wpEnvCacheDirectory(): string {
	const configFile = resolvePath( '.wp-env.json' );
	const home = process.env.WP_ENV_HOME
		? resolvePath( process.env.WP_ENV_HOME )
		: resolvePath( homedir(), existsSync( '/snap' ) ? 'wp-env' : '.wp-env' );
	const legacy = resolvePath( home, md5( configFile ) );
	if ( existsSync( legacy ) ) {
		return legacy;
	}

	const projectDirectory = basename( dirname( configFile ) );
	const shortHash = md5( configFile ).slice( 0, 8 );
	return resolvePath( home, `wp-env-${ projectDirectory }-${ shortHash }` );
}

function md5( value: string ): string {
	return createHash( 'md5' ).update( value ).digest( 'hex' );
}

async function waitForSite( up: boolean ): Promise<void> {
	for ( let attempt = 0; attempt < 40; attempt++ ) {
		try {
			const response = await fetch( 'http://localhost:8889/', { signal: AbortSignal.timeout( 2000 ) } );
			if ( up === response.ok ) {
				return;
			}
		} catch {
			if ( ! up ) {
				return;
			}
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
	}

	throw new Error( up ? 'The tests site did not come back.' : 'The tests site did not go down.' );
}

interface PostedJson {
	status: number;
	replayed: string | null;
	retryAfter: string | null;
}

/**
 * Post JSON from the Astro origin so the browser applies CORS.
 *
 * JavaScript can read `Retry-After` and `Idempotent-Replayed` only when the
 * response exposes them.
 */
async function postJsonFromBrowser( page: Page, endpoint: string, bodies: string[], key?: string ): Promise<PostedJson[]> {
	await page.goto( `${ astro }/fetch/` );
	return page.evaluate(
		async ( input: { endpoint: string; bodies: string[]; key: string } ) => {
			const responses = await Promise.all(
				input.bodies.map( async ( body ) => {
					const headers: Record<string, string> = {
						Accept: 'application/json',
						'Content-Type': 'application/json',
					};
					if ( input.key !== '' ) {
						headers[ 'Idempotency-Key' ] = input.key;
					}
					const response = await fetch( input.endpoint, { method: 'POST', headers, body } );
					return {
						status: response.status,
						replayed: response.headers.get( 'Idempotent-Replayed' ),
						retryAfter: response.headers.get( 'Retry-After' ),
					};
				} )
			);
			return responses;
		},
		{ endpoint, bodies, key: key ?? '' }
	);
}

async function clearMailbox(): Promise<void> {
	const response = await fetch( `http://127.0.0.1:${ mailPort }/api/v1/messages`, { method: 'DELETE' } );
	if ( ! response.ok ) {
		throw new Error( `Mailpit refused to clear the inbox (${ response.status }).` );
	}
}

async function waitForOneMessage( forbidden: string[] ): Promise<void> {
	let subject = '';
	for ( let attempt = 0; attempt < 20; attempt++ ) {
		const response = await fetch( `http://127.0.0.1:${ mailPort }/api/v1/messages` );
		const data = ( await response.json() ) as { total?: number; messages?: { Subject?: string }[] };
		if ( data.total === 1 && data.messages?.length === 1 ) {
			subject = data.messages[ 0 ]?.Subject ?? '';
			break;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
	}

	expect( subject, 'Mailpit did not store one message' ).not.toBe( '' );
	expect( subject ).toContain( 'E2E cross origin' );
	for ( const value of forbidden ) {
		expect( subject ).not.toContain( value );
	}
}
