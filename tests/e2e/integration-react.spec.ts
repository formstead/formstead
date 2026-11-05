import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Request } from '@playwright/test';
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { formState, wpJson } from './helpers/wp';

const site = 'http://localhost:4501';
const fixture = JSON.parse( readFileSync( 'tests/fixtures/integration-code/all-fields.json', 'utf8' ) );
const accepted = 'Thanks. Your submission was accepted.';
const unconfirmed = 'We could not confirm whether your submission was accepted. Try again with the same details.';
const receipt = { receipt_id: 'fsr_abcdefghijklmnop' };
const cors = { 'access-control-allow-origin': site, 'access-control-expose-headers': 'Retry-After' };
let root: string;
let server: ChildProcess;
let saved: { id: number; public_id: string };
let endpoint: string;

async function fill( form: Locator ) {
	for ( const [ name, value ] of Object.entries( fixture.submission ) as [string, string | string[]][] ) {
		if ( name.startsWith( '_fs_' ) ) continue;
		const control = form.locator( `[name="${ name }${ Array.isArray( value ) ? '[]' : '' }"]` );
		if ( name === 'agree' ) await control.check();
		else if ( Array.isArray( value ) || name === 'topic' || name === 'optional_topic' ) await control.selectOption( value );
		else await control.fill( value );
	}
}

test.beforeAll( async ( { browser } ) => {
	test.setTimeout( 120000 );
	const admin = await browser.newPage( { storageState: 'tests/e2e/.auth/admin.json' } );
	try {
		saved = wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', 'react' ] ) as typeof saved;
		await admin.goto( `http://localhost:8889/wp-admin/admin.php?page=formstead-forms&form=${ saved.id }` );
		await admin.addInitScript( () => Object.defineProperty( navigator, 'clipboard', { value: { writeText: async ( text: string ) => { Object.assign( window, { reactCopied: text } ); } } } ) );
		await admin.reload();
		await admin.locator( '#formstead-integration-platform' ).selectOption( 'react' );
		const source = await admin.locator( '#formstead-integration-react' ).inputValue();
		await admin.getByRole( 'button', { name: 'Copy code', exact: true } ).click();
		await expect( admin.locator( '#formstead-copy-status' ) ).toHaveText( 'Copied.' );
		expect( await admin.evaluate( () => ( window as Window & { reactCopied: string } ).reactCopied ) ).toBe( source );
		await expect( admin.locator( '#formstead-integration-unsaved' ) ).toBeHidden();
		root = mkdtempSync( resolve( 'examples/next/.integration-' ) );
		mkdirSync( `${ root }/app` );
		writeFileSync( `${ root }/app/FormsteadForm.tsx`, source );
		writeFileSync( `${ root }/app/layout.tsx`, 'export const metadata = {title: "Generated React"}; export default function Layout({children}:{children:React.ReactNode}) {return <html lang="en"><body>{children}</body></html>}' );
		writeFileSync( `${ root }/app/page.tsx`, 'import FormsteadForm from "./FormsteadForm"; export default function Page() { return <main><h1>Contact</h1><FormsteadForm/><FormsteadForm/></main> }' );
		writeFileSync( `${ root }/package.json`, readFileSync( 'examples/next/package.json' ) );
		writeFileSync( `${ root }/tsconfig.json`, readFileSync( 'examples/next/tsconfig.json' ) );
		execFileSync( 'node', [ resolve( 'examples/next/node_modules/next/dist/bin/next' ), 'build', '--webpack' ], { cwd: root, stdio: 'pipe', timeout: 90000 } );
		server = spawn( 'node', [ resolve( 'examples/next/node_modules/next/dist/bin/next' ), 'start', '--hostname', '127.0.0.1', '--port', '4501' ], { cwd: root, stdio: 'pipe' } );
		await expect.poll( async () => { try { return ( await fetch( site ) ).status; } catch { return 0; } }, { timeout: 15000 } ).toBe( 200 );
	} finally { await admin.close(); }
} );
test.afterAll( async () => {
	if ( server && server.exitCode === null ) {
		const stopped = new Promise( resolve => server.once( 'exit', resolve ) );
		server.kill( 'SIGTERM' );
		await stopped;
	}
	if ( root ) rmSync( root, { recursive: true, force: true } );
} );

test( 'copied TSX builds, hydrates two instances and posts all fields without cookies', async ( { page, browser } ) => {
	const faults: string[] = [];
	page.on( 'pageerror', error => faults.push( error.message ) );
	page.on( 'console', message => { if ( message.type() === 'error' ) faults.push( message.text() ); } );
	const nojs = await browser.newContext( { javaScriptEnabled: false } );
	const ssr = await nojs.newPage();
	await ssr.goto( site );
	const ids = await ssr.locator( 'form [id], form[id]' ).evaluateAll( nodes => nodes.map( node => node.id ) );
	await nojs.close();
	await page.goto( site );
	const form = page.locator( 'form' ).first();
	endpoint = ( await form.getAttribute( 'action' ) )!;
	await expect( form.locator( 'label' ).first() ).toHaveText( fixture.form_input.fields[0].label );
	expect( await page.locator( 'form [id], form[id]' ).evaluateAll( nodes => nodes.map( node => node.id ) ) ).toEqual( ids );
	expect( new Set( ids ).size ).toBe( ids.length );
	const posts: Request[] = [];
	page.on( 'request', r => { if ( r.method() === 'POST' && r.url() === endpoint ) posts.push( r ); } );
	await fill( form );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	expect( posts ).toHaveLength( 1 );
	expect( posts[0].headers()['content-type'] ).toMatch( /^multipart\/form-data; boundary=/ );
	expect( ( await posts[0].allHeaders() ).cookie ).toBeUndefined();
	expect( wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', String( saved.id ) ] ) ).toEqual( fixture.normalized );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( 'These details were already accepted. They have not been sent again.' );
	expect( posts ).toHaveLength( 1 );
	await expect( page.locator( 'form' ).nth( 1 ).getByRole( 'status' ) ).toBeEmpty();
	expect( faults ).toEqual( [] );
	expect( ( await new AxeBuilder( { page } ).analyze() ).violations ).toEqual( [] );
} );

test( 'real 422 maps every control and focuses the first, correction uses a new key', async ( { page } ) => {
	await page.goto( site );
	const form = page.locator( 'form' ).first();
	endpoint = ( await form.getAttribute( 'action' ) )!;
	const keys: string[] = [];
	page.on( 'request', r => { if ( r.method() === 'POST' && r.url() === endpoint ) keys.push( r.headers()['idempotency-key'] ); } );
	await fill( form );
	await form.evaluate( ( f: HTMLFormElement ) => { f.noValidate = true; } );
	await form.locator( '[name="email"]' ).fill( 'invalid' );
	await form.locator( '[name="quantity"]' ).fill( '1.5' );
	await form.locator( '[name="agree"]' ).uncheck();
	await form.locator( '[name="topic"]' ).selectOption( '' );
	await form.locator( '[name="interests[]"]' ).selectOption( [] );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( 'Check the form and try again.' );
	for ( const name of [ 'email', 'quantity', 'agree', 'topic', 'interests' ] ) {
		await expect( form.locator( `[data-formstead-field="${ name }"]` ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( form.locator( `[data-formstead-error="${ name }"]` ) ).toBeVisible();
	}
	await expect( form.locator( '[name="email"]' ) ).toBeFocused();
	await expect( form.locator( '[name="quantity"]' ) ).toHaveAttribute( 'aria-describedby', /quantity-hint .*quantity-error/ );
	expect( ( await new AxeBuilder( { page } ).analyze() ).violations ).toEqual( [] );
	await fill( form );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	expect( keys[0] ).not.toBe( keys[1] );
	await expect( form.locator( '[aria-invalid]' ) ).toHaveCount( 0 );
} );

test( 'lost response replays one submission and delivery with a synchronous double-submit guard', async ( { page } ) => {
	await page.goto( site );
	const form = page.locator( 'form' ).first();
	endpoint = ( await form.getAttribute( 'action' ) )!;
	const before = formState( saved.public_id ).submissions!;
	const posts: Request[] = [];
	page.on( 'request', r => { if ( r.method() === 'POST' && r.url() === endpoint ) posts.push( r ); } );
	let first = true;
	await page.route( endpoint, async route => {
		if ( first && route.request().method() === 'POST' ) {
			first = false;
			expect( ( await route.fetch() ).status() ).toBe( 201 );
			await route.abort();
		} else await route.continue();
	} );
	await fill( form );
	await form.locator( '[name="email"]' ).fill( 'react-lost@example.test' );
	await form.evaluate( ( f: HTMLFormElement ) => { f.requestSubmit(); f.requestSubmit(); } );
	await expect( form.getByRole( 'status' ) ).toHaveText( unconfirmed );
	expect( posts ).toHaveLength( 1 );
	const state = formState( saved.public_id );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	expect( posts ).toHaveLength( 2 );
	expect( posts[0].headers()['idempotency-key'] ).toBe( posts[1].headers()['idempotency-key'] );
	const replay = formState( saved.public_id );
	expect( replay.submissions ).toBe( before + 1 );
	expect( replay.latest?.receipt_id ).toBe( state.latest?.receipt_id );
	expect( replay.latest?.deliveries ).toHaveLength( 1 );
} );

test( 'retry status matrix, conflict, rate deadline and crypto fallback retain deliberate manual retries', async ( { page } ) => {
	await page.addInitScript( () => Object.defineProperty( crypto, 'randomUUID', { value: undefined } ) );
	await page.goto( site );
	const form = page.locator( 'form' ).first();
	endpoint = ( await form.getAttribute( 'action' ) )!;
	await fill( form );
	const keys: string[] = [];
	let response = { status: 500, body: '{}', headers: { ...cors } };
	await page.route( endpoint, async route => {
		if ( route.request().method() !== 'POST' ) { await route.continue(); return; }
		keys.push( route.request().headers()['idempotency-key'] );
		await route.fulfill( { ...response, contentType: 'application/json' } );
	} );
	for ( const status of [ 500, 503, 201, 422, 403, 404, 400 ] ) {
		response = { status, body: '{}', headers: cors };
		await form.getByRole( 'button' ).click();
		await expect( form.getByRole( 'status' ) ).toHaveText( status === 403 || status === 404 ? 'This form is unavailable. Please contact the site owner.' : status === 400 ? 'The form could not be sent. Check your details and try again.' : unconfirmed );
	}
	expect( new Set( keys ).size ).toBe( 1 );
	expect( keys[0] ).toMatch( /^[a-f0-9]{32}$/ );
	await page.clock.install();
	response = { status: 429, body: '{}', headers: { ...cors, 'Retry-After': '2' } };
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( 'Too many submissions. Try again in 2 seconds.' );
	const count = keys.length;
	await form.getByRole( 'button' ).click();
	expect( keys ).toHaveLength( count );
	await page.clock.fastForward( 2100 );
	response = { status: 409, body: '{}', headers: cors };
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toContainText( 'Check before sending again.' );
	expect( keys.at( -1 ) ).toBe( keys[0] );
	response = { status: 201, body: JSON.stringify( receipt ), headers: cors };
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	expect( keys.at( -1 ) ).not.toBe( keys[0] );
} );

test( 'server-rendered component posts natively without JavaScript', async ( { browser } ) => {
	const context = await browser.newContext( { javaScriptEnabled: false } );
	try {
		const page = await context.newPage();
		await page.goto( site );
		const form = page.locator( 'form' ).first();
		await fill( form );
		await form.getByRole( 'button' ).click();
		await expect( page ).toHaveURL( 'http://localhost:4400/thanks.html' );
	} finally { await context.close(); }
} );


test( 'real conflict warns after changed uncertain details and resets only for a manual next send', async ( { page } ) => {
	await page.goto( site );
	const form = page.locator( 'form' ).first();
	endpoint = ( await form.getAttribute( 'action' ) )!;
	let first = true;
	const keys: string[] = [];
	await page.route( endpoint, async route => {
		if ( route.request().method() !== 'POST' ) { await route.continue(); return; }
		keys.push( route.request().headers()['idempotency-key'] );
		if ( first ) { first = false; expect( ( await route.fetch() ).status() ).toBe( 201 ); await route.abort(); }
		else await route.continue();
	} );
	await fill( form );
	await form.locator( '[name="email"]' ).fill( 'react-conflict@example.test' );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( unconfirmed );
	const before = formState( saved.public_id ).submissions;
	await form.locator( '[name="message"]' ).fill( 'Changed after uncertain response' );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toContainText( 'Check before sending again.' );
	expect( keys[1] ).toBe( keys[0] );
	expect( formState( saved.public_id ).submissions ).toBe( before );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	expect( keys[2] ).not.toBe( keys[1] );
} );

test( 'rejected origin and honeypot do not create a submission', async ( { page } ) => {
	const state = formState( saved.public_id );
	const before = state.submissions;
	await page.goto( site.replace( 'localhost', '127.0.0.1' ) );
	let form = page.locator( 'form' ).first();
	await fill( form );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( unconfirmed );
	await expect( form.locator( '[name="email"]' ) ).toHaveValue( 'ada@example.com' );
	expect( formState( saved.public_id ).submissions ).toBe( before );
	await page.goto( site );
	form = page.locator( 'form' ).first();
	await fill( form );
	await form.locator( '[name="_fs_honeypot"]' ).evaluate( ( input: HTMLInputElement ) => { input.value = 'bot'; } );
	await form.getByRole( 'button' ).click();
	await expect( form.getByRole( 'status' ) ).toHaveText( accepted );
	const rejected = formState( saved.public_id );
	expect( rejected.submissions ).toBe( before );
	expect( rejected.honeypot_rejections ).toBe( ( state.honeypot_rejections ?? 0 ) + 1 );
} );
