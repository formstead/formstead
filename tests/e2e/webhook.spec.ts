import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { readForms, receiptState, wp, wpJson } from './helpers/wp';

const forms = readForms();
const vector = JSON.parse( readFileSync( 'tests/fixtures/webhook-vector.json', 'utf8' ) ) as { secret: string };

test( 'a real worker delivers a verified webhook and refuses the wrong signature', async ( { request } ) => {
	test.setTimeout( 90_000 );
	for ( const valid of [ true, false ] ) {
		const secret = valid ? vector.secret : `whsec_${ Buffer.alloc( 32, 1 ).toString( 'base64' ) }`;
		const endpoint = wpJson( [ 'eval', `
			delete_option( 'formstead_e2e_webhook_received' );
			$form = ( new Formstead\\Forms\\FormRepository() )->insert( new Formstead\\Forms\\Form(
				0, '', 'E2E webhook ${ valid ? 'valid' : 'invalid' }', true,
				array( new Formstead\\Forms\\Field( 'email', 'Email', Formstead\\Forms\\FieldType::Email, true, 255 ) ),
				1, 'https://example.com/thanks', '', '', null, 0, null, 30, array( 'email' ),
				new Formstead\\Forms\\FormWebhook( 'http://tests-wordpress:80/wp-json/formstead-e2e/v1/webhook', '${ secret }', false )
			) );
			if ( $form instanceof WP_Error ) { throw new RuntimeException( 'Webhook fixture failed.' ); }
			echo 'FORMSTEAD_E2E_JSON ' . wp_json_encode( rest_url( 'formstead/v1/forms/' . $form->public_id() . '/submissions' ) );
		` ] ) as string;
		const posted = await request.post( endpoint, {
			data: { email: `webhook-${ valid }-${ Date.now() }@example.com` },
			headers: { Accept: 'application/json', Origin: forms.base_url },
		} );
		expect( posted.status() ).toBe( 201 );
		const { receipt_id: receipt } = await posted.json() as { receipt_id: string };
		wp( [ 'formstead', 'deliveries', 'run' ] );
		const state = receiptState( receipt );
		expect( state.found ).toBe( true );
		expect( state.deliveries ).toHaveLength( 1 );
		expect( state.deliveries[0].attempts ).toBe( 1 );
		expect( state.deliveries[0].status ).toBe( valid ? 'handed_off' : 'failed' );
		if ( valid ) {
			const received = wpJson( [ 'eval', `echo 'FORMSTEAD_E2E_JSON ' . wp_json_encode( get_option( 'formstead_e2e_webhook_received' ) );` ] ) as { receipt_id: string; has_fields: boolean };
			expect( received.receipt_id ).toBe( receipt );
			expect( received.has_fields ).toBe( false );
		} else {
			expect( state.deliveries[0].last_error ).toBe( 'webhook_http_4xx' );
		}
	}
} );
