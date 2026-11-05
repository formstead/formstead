import { expect, test, type Page } from '@playwright/test';
import { formState, readForms, receiptState, wp } from './helpers/wp';
import { assertTestMailbox } from './helpers/mailpit';

const forms = readForms();
const site = 'http://localhost:4321';
const mailPort = process.env.FORMSTEAD_MAILPIT_UI_PORT ?? '8025';

function formOn( page: Page ) {
	return page.locator( 'form' ).filter( { has: page.locator( '#formstead-honeypot' ) } );
}

async function fillContact( page: Page, email: string, message: string ): Promise<void> {
	const form = formOn( page );
	await form.locator( '#formstead-email' ).fill( email );
	await form.locator( '#formstead-message' ).fill( message );
}

async function clearMailbox(): Promise<void> {
	assertTestMailbox( mailPort );
	wp( [ 'formstead', 'deliveries', 'run' ] );
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

test.describe( 'without JavaScript', () => {
	test.use( {
		javaScriptEnabled: false,
		storageState: { cookies: [], origins: [] },
	} );

	test( 'the plain page redirects after a native post', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;

		await page.goto( `${ site }/` );
		await fillContact( page, `astro-${ stamp }@example.com`, `Astro native ${ stamp }` );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		await expect( page ).toHaveURL( forms.forms.cross.success_url );
		expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before + 1 );
		expect( formState( forms.forms.cross.public_id ).latest?.status ).toBe( 'new' );
	} );

	test( 'the fetch page still posts natively when JavaScript is off', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;

		await page.goto( `${ site }/fetch` );
		await fillContact( page, `astro-fallback-${ stamp }@example.com`, `Astro fallback ${ stamp }` );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		await expect( page ).toHaveURL( forms.forms.cross.success_url );
		expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before + 1 );
	} );
} );

test.describe( 'fetch', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'a slow form stores one row and sends one message', async ( { page } ) => {
		const stamp = Date.now();
		const email = `astro-fetch-${ stamp }@example.com`;
		const message = `Astro fetch ${ stamp }`;
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
		await clearMailbox();

		await page.clock.install();
		await page.clock.pauseAt( Date.now() );
		await page.goto( `${ site }/fetch` );
		await fillContact( page, email, message );
		await page.clock.fastForward( 4000 );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.getByRole( 'status' ) ).toContainText( /Thanks\. Receipt fsr_[a-z2-7]{16}\./ );
		const after = formState( forms.forms.cross.public_id );
		expect( after.submissions ).toBe( before + 1 );
		expect( after.latest?.status ).toBe( 'new' );

		wp( [ 'formstead', 'deliveries', 'run' ] );
		await waitForOneMessage( [ email, message ] );
	} );

	test( 'an empty message is described on the field', async ( { page } ) => {
		const secret = `astro-invalid-${ Date.now() }@example.com`;
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;

		await page.goto( `${ site }/fetch` );
		await formOn( page ).locator( '#formstead-email' ).fill( secret );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		const message = page.locator( '#formstead-message' );
		await expect( message ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( message ).toHaveAttribute( 'aria-describedby', 'formstead-message-error' );
		await expect( page.locator( '#formstead-message-error' ) ).toHaveText( 'This field is required.' );
		await expect( message ).toBeFocused();
		await expect( page.locator( 'body' ) ).not.toContainText( secret );
		expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before );
	} );

	test( 'a fast fill is stored as spam and still shows thanks', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;

		await page.clock.install();
		await page.clock.pauseAt( Date.now() );
		await page.goto( `${ site }/fetch` );
		await fillContact( page, `astro-fast-${ stamp }@example.com`, `Astro fast ${ stamp }` );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.getByRole( 'status' ) ).toContainText( /Thanks\. Receipt fsr_[a-z2-7]{16}\./ );
		const after = formState( forms.forms.cross.public_id );
		expect( after.submissions ).toBe( before + 1 );
		expect( after.latest?.status ).toBe( 'spam' );
		expect( after.latest?.deliveries ?? [] ).toHaveLength( 0 );
	} );

	test( 'a closed origin shows a failure and stores nothing', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.closed.public_id ).submissions ?? 0;

		await page.goto( `${ site }/closed` );
		await fillContact( page, `astro-closed-${ stamp }@example.com`, `Astro closed ${ stamp }` );
		await formOn( page ).getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.getByRole( 'status' ) ).toHaveText( 'We could not confirm whether your submission was accepted. Try again with the same details.' );
		expect( formState( forms.forms.closed.public_id ).submissions ).toBe( before );
	} );
} );

test( 'the JSON timing example replays a lost response with the same frozen body', async ( { page } ) => {
	const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
	await page.goto( `${ site }/fetch` );
	const form = formOn( page );
	const endpoint = ( await form.getAttribute( 'action' ) )!;
	const posts: { key: string; body: string | null }[] = [];
	let receipt = '';
	let first = true;
	await page.route( endpoint, async route => {
		if ( route.request().method() !== 'POST' ) { await route.continue(); return; }
		posts.push( { key: route.request().headers()['idempotency-key'], body: route.request().postData() } );
		if ( first ) {
			first = false;
			const response = await route.fetch();
			expect( response.status() ).toBe( 201 );
			receipt = ( await response.json() ).receipt_id;
			await route.abort();
		} else await route.continue();
	} );
	await fillContact( page, `astro-lost-${ Date.now() }@example.com`, 'Frozen timing retry' );
	await page.clock.install();
	await page.clock.fastForward( 4000 );
	await form.getByRole( 'button', { name: 'Send' } ).click();
	await expect( page.getByRole( 'status' ) ).toContainText( 'We could not confirm' );
	await page.clock.fastForward( 5000 );
	await form.getByRole( 'button', { name: 'Send' } ).click();
	await expect( page.getByRole( 'status' ) ).toContainText( receipt );
	expect( posts ).toHaveLength( 2 );
	expect( posts[1] ).toEqual( posts[0] );
	expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before + 1 );
	expect( receiptState( receipt ).deliveries ).toHaveLength( 1 );
	await form.getByRole( 'button', { name: 'Send' } ).click();
	await expect( page.getByRole( 'status' ) ).toContainText( 'They have not been sent again.' );
	expect( posts ).toHaveLength( 2 );
} );
