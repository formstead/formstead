import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readForms, wp, wpJson } from './helpers/wp';

const forms = readForms();

test( 'the WordPress export includes later pages after an earlier row is deleted', async ( { page } ) => {
	test.setTimeout( 120_000 );
	const email = `export-pages-${ Date.now() }@example.com`;
	const seeded = wpJson( [ 'eval', `
		$form = ( new Formstead\\Forms\\FormRepository() )->find_by_public_id( '${ forms.forms.same.public_id }' );
		$ids = array(); $receipts = array();
		for ( $i = 0; $i < 251; ++$i ) {
			$result = ( new Formstead\\Submissions\\SubmissionIntake() )->accept( $form, array( 'email' => '${ email }', 'message' => 'Export row ' . $i ), null );
			if ( ! $result->submission() ) { throw new RuntimeException( 'Export fixture failed.' ); }
			$ids[] = $result->submission()->id(); $receipts[] = $result->submission()->receipt_id();
		}
		$request_id = wp_create_user_request( '${ email }', 'export_personal_data' );
		wp_update_post( array( 'ID' => $request_id, 'post_status' => 'request-confirmed' ) );
		$keys = array_keys( apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		echo 'FORMSTEAD_E2E_JSON ' . wp_json_encode( array( 'first' => $ids[0], 'receipts' => $receipts, 'exporter' => array_search( 'formstead', $keys, true ) + 1 ) );
	` ] ) as { first: number; receipts: string[]; exporter: number };

	let changed = false;
	await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
		const body = new URLSearchParams( route.request().postData() || '' );
		if ( body.get( 'action' ) === 'wp-privacy-export-personal-data' && Number( body.get( 'exporter' ) ) === seeded.exporter && body.get( 'page' ) === '1' && ! changed ) {
			const response = await route.fetch();
			wp( [ 'eval', `( new Formstead\\Submissions\\SubmissionDeleter() )->delete( array( ${ seeded.first } ) );` ] );
			changed = true;
			await route.fulfill( { response } );
			return;
		}
		await route.continue();
	} );

	await page.goto( '/wp-admin/export-personal-data.php' );
	const row = page.getByRole( 'row', { name: email } );
	await row.hover();
	const downloaded = page.waitForEvent( 'download', { timeout: 90_000 } );
	await row.getByRole( 'button', { name: 'Download personal data', exact: true } ).click();
	const download = await downloaded;
	const path = await download.path();
	expect( path ).toBeTruthy();
	const report = execFileSync( 'unzip', [ '-p', path! ], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 } );
	expect( changed ).toBe( true );
	for ( const receipt of seeded.receipts ) {
		expect( report ).toContain( receipt );
	}
} );

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
