import { expect, test } from '@playwright/test';
import { formState, readForms } from './helpers/wp';

const forms = readForms();
const examples = 'http://localhost:4400';

function formOn( page: import('@playwright/test').Page ) {
	return page.locator( 'form' ).filter( { has: page.locator( '#formstead-honeypot' ) } );
}

test.describe( 'forms without JavaScript', () => {
	test.use( {
		javaScriptEnabled: false,
		storageState: { cookies: [], origins: [] },
	} );

	test( 'a same-site submit redirects to the thanks page', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.same.public_id ).submissions ?? 0;
		const form = formOn( page );

		await page.goto( forms.forms.same.page_url ?? '' );
		await form.locator( '#formstead-email' ).fill( `same-${ stamp }@example.com` );
		await form.locator( '#formstead-message' ).fill( `Same site ${ stamp }` );
		await form.getByRole( 'button', { name: 'Send' } ).click();

		await expect( page ).toHaveURL( forms.forms.same.success_url );
		await expect( page.getByText( 'The form was sent.' ) ).toBeVisible();
		expect( formState( forms.forms.same.public_id ).submissions ).toBe( before + 1 );
	} );

	test( 'a cross-origin submit redirects to the example thanks page', async ( { page } ) => {
		const stamp = Date.now();
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
		const form = formOn( page );

		await page.goto( `${ examples }/cross-origin.html` );
		await form.locator( '#formstead-email' ).fill( `cross-${ stamp }@example.com` );
		await form.locator( '#formstead-message' ).fill( `Cross origin ${ stamp }` );
		await form.getByRole( 'button', { name: 'Send' } ).click();

		await expect( page ).toHaveURL( forms.forms.cross.success_url );
		await expect( page.getByRole( 'heading', { name: 'Thanks' } ) ).toBeVisible();
		expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before + 1 );
	} );

	test( 'a missing field shows the error page and does not echo the value', async ( { page } ) => {
		const secret = `secret-${ Date.now() }@example.com`;
		const before = formState( forms.forms.cross.public_id ).submissions ?? 0;
		const form = formOn( page );

		await page.goto( `${ examples }/cross-origin.html` );
		await form.locator( '#formstead-email' ).fill( secret );
		await form.getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.getByRole( 'heading', { name: 'Check the form and try again' } ) ).toBeVisible();
		await expect( page.getByRole( 'listitem' ) ).toContainText( 'Message: This field is required.' );
		await expect( page.locator( 'body' ) ).not.toContainText( secret );
		expect( formState( forms.forms.cross.public_id ).submissions ).toBe( before );
	} );

	test( 'a rejected origin shows the HTML refusal and stores nothing', async ( { page } ) => {
		const stamp = Date.now();
		const secret = `closed-${ stamp }@example.com`;
		const message = `Closed note ${ stamp }`;
		const before = formState( forms.forms.closed.public_id ).submissions ?? 0;
		const form = formOn( page );

		await page.goto( `${ examples }/cross-origin.html?form=closed` );
		await form.locator( '#formstead-email' ).fill( secret );
		await form.locator( '#formstead-message' ).fill( message );
		await form.getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.getByRole( 'heading', { name: 'Submission not accepted' } ) ).toBeVisible();
		await expect( page.locator( 'body' ) ).not.toContainText( secret );
		await expect( page.locator( 'body' ) ).not.toContainText( message );
		expect( formState( forms.forms.closed.public_id ).submissions ).toBe( before );
	} );
} );

test( 'a filled honeypot redirects without storing and the admin count increases', async ( {
	browser,
	page,
} ) => {
	const before = formState( forms.forms.same.public_id );
	const context = await browser.newContext( { javaScriptEnabled: false } );
	const formPage = await context.newPage();
	const stamp = Date.now();
	const form = formOn( formPage );

	await formPage.goto( forms.forms.same.page_url ?? '' );
	await form.locator( '#formstead-email' ).fill( `honey-${ stamp }@example.com` );
	await form.locator( '#formstead-message' ).fill( `Honey ${ stamp }` );
	await form.locator( '#formstead-honeypot' ).fill( 'bot', { force: true } );
	await form.getByRole( 'button', { name: 'Send' } ).click();
	await expect( formPage ).toHaveURL( forms.forms.same.success_url );
	await context.close();

	const after = formState( forms.forms.same.public_id );
	expect( after.submissions ).toBe( before.submissions ?? 0 );
	expect( after.honeypot_rejections ).toBe( ( before.honeypot_rejections ?? 0 ) + 1 );

	await page.goto( '/wp-admin/admin.php?page=formstead-forms' );
	const row = page.getByRole( 'row', { name: /E2E same site/ } );
	await expect( row.getByRole( 'cell' ).nth( 3 ) ).toHaveText( String( after.honeypot_rejections ) );
} );
