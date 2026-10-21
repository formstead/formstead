import { expect, test } from '@playwright/test';
import { formState, readForms, wp } from './helpers/wp';

const forms = readForms();

test.use( { storageState: { cookies: [], origins: [] } } );

test( 'a native POST on the same site returns 303 and stores one row', async ( { request } ) => {
	const stamp = Date.now();
	const email = `harness-${ stamp }@example.com`;
	const message = `Harness note ${ stamp }`;
	const before = formState( forms.forms.same.public_id );

	const response = await request.post( forms.forms.same.endpoint, {
		form: { email, message },
		maxRedirects: 0,
		headers: { Origin: forms.base_url },
	} );

	expect( response.status() ).toBe( 303 );
	expect( response.headers().location ).toBe( forms.forms.same.success_url );

	const after = formState( forms.forms.same.public_id );
	expect( after.submissions ).toBe( ( before.submissions ?? 0 ) + 1 );
	expect( after.latest?.status ).toBe( 'new' );

	const encoded = JSON.stringify( after );
	expect( encoded ).not.toContain( email );
	expect( encoded ).not.toContain( message );
	expect( encoded ).not.toContain( 'payload' );
} );

test( 'the public route does not list submissions', async ( { request } ) => {
	const stamp = Date.now();
	const email = `hidden-${ stamp }@example.com`;
	const message = `Hidden note ${ stamp }`;

	const posted = await request.post( forms.forms.same.endpoint, {
		form: { email, message },
		maxRedirects: 0,
		headers: { Origin: forms.base_url },
	} );
	expect( posted.status() ).toBe( 303 );

	const listed = await request.get( forms.forms.same.endpoint );
	expect( listed.status() ).toBe( 404 );
	const body = await listed.text();
	expect( body ).not.toContain( email );
	expect( body ).not.toContain( message );
} );

test( 'retention run finishes on the seeded site', async () => {
	const output = wp( [ 'formstead', 'retention', 'run' ] );
	expect( output ).toMatch( /Deleted \d+ submissions\.|Retention is already running\./ );
} );
