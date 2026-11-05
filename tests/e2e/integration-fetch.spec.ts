import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page, type Request } from '@playwright/test';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { formState, wpJson } from './helpers/wp';

const fixture = JSON.parse( readFileSync( 'tests/fixtures/integration-code/all-fields.json', 'utf8' ) ) as {
	submission: Record<string, string | string[]>;
	normalized: Record<string, string | string[] | boolean>;
};
const accepted = 'Thanks. Your submission was accepted.';
const unconfirmed = 'We could not confirm whether your submission was accepted. Try again with the same details.';
const receipt = { receipt_id: 'fsr_abcdefghijklmnop' };
const cors = {
	'access-control-allow-origin': 'http://localhost:4400',
	'access-control-expose-headers': 'Retry-After',
};
const generatedFiles: string[] = [];
test.afterEach( () => {
	generatedFiles.splice( 0 ).forEach( file => rmSync( file, { force: true } ) );
} );

async function generate( admin: Page, notify = false ) {
	const form = wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', ...( notify ? [ 'notify' ] : [] ) ] ) as { id: number; public_id: string };
	await admin.goto( `/wp-admin/admin.php?page=formstead-forms&form=${ form.id }` );
	await admin.locator( '#formstead-integration-platform' ).selectOption( 'javascript' );
	const code = await admin.locator( '#formstead-integration-javascript' ).inputValue();
	return { ...form, code };
}

async function mount( page: Page, code: string, origin = 'http://localhost:4400' ) {
	const id = randomUUID();
	const directory = 'tests/e2e/.auth/integration-code';
	mkdirSync( directory, { recursive: true } );
	const file = `${ directory }/${ id }.html`;
	writeFileSync( file, `<!doctype html><html lang="en"><title>Fetch test</title><body><main><h1>Contact</h1>${ code }</main></body></html>` );
	generatedFiles.push( file );
	const url = `${ origin }/generated/${ id }.html`;
	await page.goto( url );
	return page.locator( 'form.formstead-form' );
}

async function fill( form: Locator ) {
	for ( const [ name, value ] of Object.entries( fixture.submission ) ) {
		if ( name.startsWith( '_fs_' ) ) {
			continue;
		}
		const control = form.locator( `[name="${ name }${ Array.isArray( value ) ? '[]' : '' }"]` );
		if ( name === 'agree' ) {
			await control.check();
		} else if ( Array.isArray( value ) || name === 'topic' || name === 'optional_topic' ) {
			await control.selectOption( value );
		} else {
			await control.fill( value );
		}
	}
}

function recordPosts( page: Page, publicId: string ) {
	const requests: Request[] = [];
	page.on( 'request', request => {
		if ( request.method() === 'POST' && request.url().includes( publicId ) ) {
			requests.push( request );
		}
	} );
	return requests;
}

test( 'generated fetch submits exact values through real multipart and CORS without cookies', async ( { page } ) => {
	const saved = await generate( page );
	const posts = recordPosts( page, saved.public_id );
	const form = await mount( page, saved.code );
	await fill( form );
	await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	expect( new URL( page.url() ).pathname ).toMatch( /^\/generated\/.+\.html$/ );
	expect( posts ).toHaveLength( 1 );
	expect( posts[0].headers()['content-type'] ).toMatch( /^multipart\/form-data; boundary=/ );
	expect( ( await posts[0].allHeaders() ).cookie ).toBeUndefined();
	expect( posts[0].headers()['idempotency-key'] ).toBeTruthy();
	expect( formState( saved.public_id ).submissions ).toBe( 1 );
	expect( wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', String( saved.id ) ] ) ).toEqual( fixture.normalized );
	const a11y = await new AxeBuilder( { page } ).analyze();
	expect( a11y.violations ).toEqual( [] );
} );

test( 'enhanced snippet retains the native POST when frontend JavaScript is disabled', async ( { page, browser } ) => {
	const saved = await generate( page );
	const context = await browser.newContext( { javaScriptEnabled: false, storageState: { cookies: [], origins: [] } } );
	try {
		const frontend = await context.newPage();
		const form = await mount( frontend, saved.code );
		await fill( form );
		await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
		await expect( frontend ).toHaveURL( 'http://localhost:4400/thanks.html' );
		expect( formState( saved.public_id ).submissions ).toBe( 1 );
	} finally {
		await context.close();
	}
} );

test( 'real 422 maps errors to text, number, checkbox and select while preserving descriptions', async ( { page } ) => {
	const saved = await generate( page );
	const posts = recordPosts( page, saved.public_id );
	const form = await mount( page, saved.code );
	await fill( form );
	await form.evaluate( ( element: HTMLFormElement ) => { element.noValidate = true; } );
	await form.locator( '[name="email"]' ).fill( 'invalid' );
	await form.locator( '[name="quantity"]' ).fill( '1.5' );
	await form.locator( '[name="agree"]' ).uncheck();
	await form.locator( '[name="topic"]' ).selectOption( '' );
	await form.locator( '[name="interests[]"]' ).selectOption( [] );
	await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( 'Check the form and try again.' );
	for ( const name of [ 'email', 'quantity', 'agree', 'topic', 'interests' ] ) {
		await expect( form.locator( `[data-formstead-field="${ name }"]` ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( form.locator( `[data-formstead-error="${ name }"]` ) ).toBeVisible();
	}
	await expect( form.locator( '[name="email"]' ) ).toBeFocused();
	const descriptions = await form.locator( '[name="quantity"]' ).getAttribute( 'aria-describedby' );
	expect( descriptions ).toContain( '-quantity-hint' );
	expect( descriptions ).toContain( '-quantity-error' );
	const a11y = await new AxeBuilder( { page } ).analyze();
	expect( a11y.violations ).toEqual( [] );
	await fill( form );
	await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	await expect( form.locator( '[aria-invalid]' ) ).toHaveCount( 0 );
	await expect( form.locator( '[name="quantity"]' ) ).toHaveAttribute( 'aria-describedby', descriptions! );
	expect( posts[0].headers()['idempotency-key'] ).not.toBe( posts[1].headers()['idempotency-key'] );
	expect( formState( saved.public_id ).submissions ).toBe( 1 );
} );

test( 'a lost response after commit replays the same key without a duplicate submission or delivery', async ( { page } ) => {
	const saved = await generate( page, true );
	const form = await mount( page, saved.code );
	const endpoint = ( await form.getAttribute( 'action' ) )!;
	const posts = recordPosts( page, saved.public_id );
	let first = true;
	await page.route( endpoint, async route => {
		if ( route.request().method() === 'POST' && first ) {
			first = false;
			const response = await route.fetch();
			expect( response.status() ).toBe( 201 );
			await route.abort( 'failed' );
		} else {
			await route.continue();
		}
	} );
	await fill( form );
	const send = form.getByRole( 'button', { name: 'Send', exact: true } );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( unconfirmed );
	const committed = formState( saved.public_id );
	expect( committed.submissions ).toBe( 1 );
	expect( committed.latest?.deliveries ).toHaveLength( 1 );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	const replay = formState( saved.public_id );
	expect( replay.submissions ).toBe( 1 );
	expect( replay.latest?.receipt_id ).toBe( committed.latest?.receipt_id );
	expect( replay.latest?.deliveries ).toHaveLength( 1 );
	expect( posts ).toHaveLength( 2 );
	expect( posts[0].headers()['idempotency-key'] ).toBe( posts[1].headers()['idempotency-key'] );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( 'These details were already accepted. They have not been sent again.' );
	expect( posts ).toHaveLength( 2 );
} );

test( 'changed details after an uncertain commit produce a real 409 and require a manual new send', async ( { page } ) => {
	const saved = await generate( page );
	const form = await mount( page, saved.code );
	const endpoint = ( await form.getAttribute( 'action' ) )!;
	const posts = recordPosts( page, saved.public_id );
	let first = true;
	await page.route( endpoint, async route => {
		if ( route.request().method() === 'POST' && first ) {
			first = false;
			expect( ( await route.fetch() ).status() ).toBe( 201 );
			await route.abort();
		} else {
			await route.continue();
		}
	} );
	await fill( form );
	const send = form.getByRole( 'button', { name: 'Send', exact: true } );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( unconfirmed );
	await form.locator( '[name="message"]' ).fill( 'Changed after the lost response' );
	const conflict = page.waitForResponse( response => response.request().method() === 'POST' && response.url() === endpoint );
	await send.click();
	expect( ( await conflict ).status() ).toBe( 409 );
	await expect( form.locator( '[data-formstead-status]' ) ).toContainText( 'Check before sending again.' );
	expect( posts ).toHaveLength( 2 );
	expect( formState( saved.public_id ).submissions ).toBe( 1 );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	expect( posts ).toHaveLength( 3 );
	expect( posts[0].headers()['idempotency-key'] ).toBe( posts[1].headers()['idempotency-key'] );
	expect( posts[2].headers()['idempotency-key'] ).not.toBe( posts[1].headers()['idempotency-key'] );
	expect( formState( saved.public_id ).submissions ).toBe( 2 );
} );

test( 'unreadable responses and HTTP failures preserve values, release controls and never execute response HTML', async ( { page } ) => {
	const saved = await generate( page );
	const form = await mount( page, saved.code );
	const endpoint = ( await form.getAttribute( 'action' ) )!;
	const posts = recordPosts( page, saved.public_id );
	const cases = [
		{ status: 201, body: '<script>window.responseExecuted = true</script>', message: unconfirmed },
		{ status: 201, body: '{}', message: unconfirmed },
		{ status: 422, body: '{invalid', message: unconfirmed },
		{ status: 422, body: '{"error":{"fields":[]}}', message: unconfirmed },
		{ status: 500, body: 'Not JSON', message: unconfirmed },
		{ status: 503, body: 'Not JSON', message: unconfirmed },
		{ status: 403, body: 'Forbidden', message: 'This form is unavailable. Please contact the site owner.' },
		{ status: 404, body: 'Missing', message: 'This form is unavailable. Please contact the site owner.' },
		{ status: 400, body: 'Bad request', message: 'The form could not be sent. Check your details and try again.' },
		{ status: 413, body: 'Too large', message: 'The form could not be sent. Check your details and try again.' },
		{ status: 415, body: 'Unsupported', message: 'The form could not be sent. Check your details and try again.' },
		{ status: 429, body: 'Limited', message: 'Too many submissions. Try again later.' },
	];
	let current = cases[0];
	await page.route( endpoint, route => route.request().method() === 'OPTIONS' ? route.continue() : route.fulfill( {
		status: current.status, body: current.body, headers: { ...cors, 'retry-after': 'invalid' },
	} ) );
	await fill( form );
	for ( const item of cases ) {
		current = item;
		const response = page.waitForResponse( response => response.request().method() === 'POST' && response.url() === endpoint );
		await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
		expect( ( await response ).status() ).toBe( current.status );
		await expect( form.getByRole( 'button', { name: 'Send', exact: true } ) ).toBeEnabled();
		await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( current.message );
		await expect( form.locator( '[name="email"]' ) ).toHaveValue( 'ada@example.com' );
	}
	expect( new Set( posts.map( request => request.headers()['idempotency-key'] ) ).size ).toBe( 1 );
	expect( await page.evaluate( () => 'responseExecuted' in window ) ).toBe( false );
} );

test( 'Retry-After delays manual sends, fallback keys work and concurrent or confirmed repeats do not send again', async ( { page } ) => {
	await page.addInitScript( () => { Object.defineProperty( Crypto.prototype, 'randomUUID', { value: undefined } ); } );
	const saved = await generate( page );
	const form = await mount( page, saved.code );
	const endpoint = ( await form.getAttribute( 'action' ) )!;
	const posts = recordPosts( page, saved.public_id );
	let count = 0;
	let release!: () => void;
	const pending = new Promise<void>( resolve => { release = resolve; } );
	await page.clock.install();
	await page.route( endpoint, async route => {
		if ( route.request().method() === 'OPTIONS' ) {
			await route.continue();
		} else if ( count++ === 0 ) {
			await route.fulfill( { status: 429, body: '{}', headers: { ...cors, 'Retry-After': '2' } } );
		} else {
			await pending;
			await route.fulfill( { status: 201, json: receipt, headers: cors } );
		}
	} );
	await fill( form );
	const send = form.getByRole( 'button', { name: 'Send', exact: true } );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toContainText( '2 seconds' );
	await send.click();
	expect( posts ).toHaveLength( 1 );
	await page.clock.fastForward( 2100 );
	await send.click();
	await expect( send ).toBeDisabled();
	await expect.poll( () => posts.length ).toBe( 2 );
	await form.evaluate( element => { element.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) ); } );
	expect( posts ).toHaveLength( 2 );
	release();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	await send.click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( 'These details were already accepted. They have not been sent again.' );
	expect( posts ).toHaveLength( 2 );
	await form.locator( '[name="message"]' ).fill( 'Another logical submission' );
	const response = page.waitForResponse( response => response.request().method() === 'POST' && response.url() === endpoint );
	await send.click();
	await response;
	await expect( send ).toBeEnabled();
	expect( posts ).toHaveLength( 3 );
	const keys = posts.map( request => request.headers()['idempotency-key'] );
	expect( keys[0] ).toMatch( /^[a-f0-9]{32}$/ );
	expect( keys[1] ).toBe( keys[0] );
	expect( keys[2] ).not.toBe( keys[1] );
} );

test( 'two generated snippets keep independent status and idempotency state', async ( { page } ) => {
	const first = await generate( page );
	const second = await generate( page );
	const firstRequests = recordPosts( page, first.public_id );
	const secondRequests = recordPosts( page, second.public_id );
	const forms = await mount( page, first.code + second.code );
	await fill( forms.nth( 0 ) );
	await fill( forms.nth( 1 ) );
	await forms.nth( 0 ).getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( forms.nth( 0 ).locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	await expect( forms.nth( 1 ).locator( '[data-formstead-status]' ) ).toBeEmpty();
	await forms.nth( 1 ).getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( forms.nth( 1 ).locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	expect( firstRequests[0].headers()['idempotency-key'] ).not.toBe( secondRequests[0].headers()['idempotency-key'] );
	expect( formState( first.public_id ).submissions ).toBe( 1 );
	expect( formState( second.public_id ).submissions ).toBe( 1 );
} );

test( 'a rejected origin cannot store a submission and leaves entered values intact', async ( { page } ) => {
	const saved = await generate( page );
	const form = await mount( page, saved.code, 'http://127.0.0.1:4400' );
	await fill( form );
	await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( unconfirmed );
	await expect( form.getByRole( 'button', { name: 'Send', exact: true } ) ).toBeEnabled();
	await expect( form.locator( '[name="email"]' ) ).toHaveValue( 'ada@example.com' );
	expect( formState( saved.public_id ).submissions ).toBe( 0 );
} );

test( 'the generated honeypot returns apparent success without a submission or notification', async ( { page } ) => {
	const saved = await generate( page, true );
	const form = await mount( page, saved.code );
	await fill( form );
	await form.locator( '[name="_fs_honeypot"]' ).evaluate( ( field: HTMLInputElement ) => { field.value = 'bot'; } );
	await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
	await expect( form.locator( '[data-formstead-status]' ) ).toHaveText( accepted );
	const state = formState( saved.public_id );
	expect( state.submissions ).toBe( 0 );
	expect( state.latest ).toBeNull();
	expect( state.honeypot_rejections ).toBe( 1 );
} );
