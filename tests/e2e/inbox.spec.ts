import { expect, test } from '@playwright/test';
import { readForms } from './helpers/wp';

const forms = readForms();

test( 'search by email matches a different case and skips another address', async ( { page, request } ) => {
	const stamp = Date.now();
	const email = `Search-${ stamp }@Example.com`;
	const other = `other-${ stamp }@example.com`;

	const created = await request.post( forms.forms.same.endpoint, {
		form: {
			email,
			message: `Inbox search ${ stamp }`,
		},
		maxRedirects: 0,
		headers: { Origin: forms.base_url },
	} );
	expect( created.status() ).toBe( 303 );

	await page.goto( '/wp-admin/admin.php?page=formstead' );
	await page.getByLabel( 'Search by email' ).fill( `search-${ stamp }@example.com` );
	await page.getByRole( 'button', { name: 'Filter' } ).click();
	await expect( page.getByRole( 'row', { name: email } ) ).toBeVisible();

	await page.getByLabel( 'Search by email' ).fill( other );
	await page.getByRole( 'button', { name: 'Filter' } ).click();
	await expect( page.getByRole( 'row', { name: email } ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'row', { name: other } ) ).toHaveCount( 0 );
} );
