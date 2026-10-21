import { expect, test } from '@playwright/test';
import { readForms, wp } from './helpers/wp';

const forms = readForms();

test( 'erasing personal data removes that address from the inbox', async ( { page, request } ) => {
	const stamp = Date.now();
	const email = `erase-${ stamp }@example.com`;
	const other = `kept-${ stamp }@example.com`;

	const created = await request.post( forms.forms.same.endpoint, {
		form: {
			email,
			message: `Erase me ${ stamp }`,
		},
		maxRedirects: 0,
		headers: { Origin: forms.base_url },
	} );
	expect( created.status() ).toBe( 303 );

	const kept = await request.post( forms.forms.same.endpoint, {
		form: {
			email: other,
			message: `Keep me ${ stamp }`,
		},
		maxRedirects: 0,
		headers: { Origin: forms.base_url },
	} );
	expect( kept.status() ).toBe( 303 );

	wp( [
		'eval',
		`wp_update_post( array( 'ID' => wp_create_user_request( '${ email }', 'remove_personal_data' ), 'post_status' => 'request-confirmed' ) );`,
	] );

	await page.goto( '/wp-admin/erase-personal-data.php' );
	const row = page.getByRole( 'row', { name: email } );
	await row.hover();
	await row.getByRole( 'button', { name: 'Erase personal data' } ).click();
	await expect( page.getByText( 'All of the personal data found for this user was erased.' ) ).toBeVisible();

	await page.goto( '/wp-admin/admin.php?page=formstead' );
	await expect( page.getByRole( 'row', { name: email } ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'row', { name: other } ) ).toBeVisible();
} );
