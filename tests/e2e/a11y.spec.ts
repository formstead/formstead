import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { readForms, wp } from './helpers/wp';

const forms = readForms();
const tags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

test.describe( 'public error pages', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'the 422, 403, 404, and 500 pages have no accessibility violations', async ( { page } ) => {
		const stamp = Date.now();

		await page.goto( 'http://localhost:4400/cross-origin.html' );
		await fill( page, `a11y-${ stamp }@example.com`, '' );
		await send( page );
		await expect( page.getByRole( 'heading', { name: 'Check the form and try again' } ) ).toBeVisible();
		await expectAccessible( page );

		await page.goto( 'http://localhost:4400/cross-origin.html?form=closed' );
		await fill( page, `a11y-closed-${ stamp }@example.com`, `Closed ${ stamp }` );
		await send( page );
		await expect( page.getByRole( 'heading', { name: 'Submission not accepted' } ) ).toBeVisible();
		await expectAccessible( page );

		const missing = forms.forms.same.endpoint.replace( forms.forms.same.public_id, 'fs_aaaaaaaaaaaaaaaa' );
		const notFound = await page.request.post( missing, {
			form: { email: `missing-${ stamp }@example.com`, message: `Missing ${ stamp }` },
			maxRedirects: 0,
		} );
		expect( notFound.status() ).toBe( 404 );
		await page.setContent( await notFound.text() );
		await expect( page.getByRole( 'heading', { name: 'Form not found' } ) ).toBeVisible();
		await expectAccessible( page );

		setFault( 'storage' );
		try {
			await page.goto( 'http://localhost:4400/cross-origin.html' );
			await fill( page, `a11y-storage-${ stamp }@example.com`, `Storage ${ stamp }` );
			await send( page );
			await expect( page.getByRole( 'heading', { name: 'Something went wrong' } ) ).toBeVisible();
			await expectAccessible( page );
		} finally {
			clearFault();
		}
	} );
} );

test( 'admin screens have no accessibility violations', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=formstead-forms' );
	await expect( page.getByRole( 'heading', { name: 'Forms' } ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );

	await page.getByRole( 'link', { name: 'E2E same site' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Edit form' } ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );

	const stamp = Date.now();
	const email = `a11y-detail-${ stamp }@example.com`;
	const created = await page.request.post( forms.forms.same.endpoint, {
		form: {
			email,
			message: `A11y detail ${ stamp }`,
		},
		maxRedirects: 0,
	} );
	expect( created.status() ).toBe( 303 );

	await page.goto( '/wp-admin/admin.php?page=formstead' );
	await expect( page.getByRole( 'heading', { name: 'Submissions' } ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );

	await page.getByRole( 'row', { name: email } ).getByRole( 'link' ).first().click();
	await expect( page.getByRole( 'heading', { name: 'Submission', level: 1 } ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );

	await page.goto( '/wp-admin/admin.php?page=formstead-settings' );
	await expect( page.getByRole( 'heading', { name: 'Settings' } ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );
} );

test( 'the field editor can add and remove a row from the keyboard', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=formstead-forms' );
	await page.getByRole( 'link', { name: 'E2E same site' } ).click();
	const rows = page.locator( '#formstead-fields [data-field-row]' );
	const before = await rows.count();

	await page.locator( '#formstead-add-field' ).focus();
	await page.keyboard.press( 'Enter' );
	await expect( rows ).toHaveCount( before + 1 );

	await rows.last().locator( '[data-remove-field]' ).focus();
	await page.keyboard.press( 'Enter' );
	await expect( rows ).toHaveCount( before );
} );

async function expectAccessible( page: Page, include?: string ): Promise<void> {
	let builder = new AxeBuilder( { page } ).withTags( tags );
	if ( include ) {
		builder = builder.include( include );
	}
	const results = await builder.analyze();
	const summary = results.violations
		.map( ( violation ) => `${ violation.id }: ${ violation.help } (${ violation.nodes.length })` )
		.join( '\n' );
	expect( results.violations, summary ).toEqual( [] );
}

async function fill( page: Page, email: string, message: string ): Promise<void> {
	const form = page.locator( 'form' ).filter( { has: page.locator( '#formstead-honeypot' ) } );
	await form.locator( '#formstead-email' ).fill( email );
	if ( message !== '' ) {
		await form.locator( '#formstead-message' ).fill( message );
	}
}

async function send( page: Page ): Promise<void> {
	await page
		.locator( 'form' )
		.filter( { has: page.locator( '#formstead-honeypot' ) } )
		.getByRole( 'button', { name: 'Send' } )
		.click();
}

function setFault( value: 'storage' ): void {
	wp( [ 'eval', `update_option( "formstead_e2e_fault", "${ value }", false );` ] );
}

function clearFault(): void {
	wp( [ 'eval', 'delete_option( "formstead_e2e_fault" );' ] );
}
