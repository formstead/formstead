import { expect, test } from '@playwright/test';

const site = 'http://localhost:4500';

test.describe( 'Next.js', () => {
	test( 'the route handler stores a submission in the inbox', async ( { page } ) => {
		const stamp = Date.now();
		const email = `next-route-${ stamp }@example.com`;

		await page.goto( `${ site }/server` );
		await page.locator( '#formstead-email' ).fill( email );
		await page.locator( '#formstead-message' ).fill( `Next route ${ stamp }` );
		await page.waitForFunction( () => performance.now() >= 3500 );
		await page.getByRole( 'button', { name: 'Send' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks. Receipt' );

		await page.goto( '/wp-admin/admin.php?page=formstead' );
		await page.getByLabel( 'Search by email' ).fill( email );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page.getByRole( 'row', { name: email } ) ).toBeVisible();
	} );

	test( 'the browser posts straight to Formstead', async ( { page } ) => {
		const stamp = Date.now();

		await page.goto( site );
		await page.locator( '#formstead-email' ).fill( `next-client-${ stamp }@example.com` );
		await page.locator( '#formstead-message' ).fill( `Next client ${ stamp }` );
		await page.getByRole( 'button', { name: 'Send' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'Thanks. Receipt' );
	} );
} );
