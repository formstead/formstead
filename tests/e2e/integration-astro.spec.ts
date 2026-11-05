import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomUUID } from 'node:crypto';
import { formState, wpJson } from './helpers/wp';

test( 'copied Astro component compiles hostile text and submits every field without JavaScript', async ( { page, browser } ) => {
	test.setTimeout( 60000 );
	await page.addInitScript( () => {
		Object.defineProperty( navigator, 'clipboard', { value: { writeText: async ( text: string ) => { Object.assign( window, { astroCopied: text } ); } } } );
	} );
	const saved = wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php' ] ) as { id: number; public_id: string };
	await page.goto( `/wp-admin/admin.php?page=formstead-forms&form=${ saved.id }` );
	await page.locator( '#formstead-integration-platform' ).selectOption( 'astro' );
	const source = await page.locator( '#formstead-integration-astro' ).inputValue();
	await page.getByRole( 'button', { name: 'Copy code', exact: true } ).click();
	await expect( page.locator( '#formstead-copy-status' ) ).toHaveText( 'Copied.' );
	expect( await page.evaluate( () => ( window as Window & { astroCopied: string } ).astroCopied ) ).toBe( source );
	await expect( page.locator( '#formstead-integration-unsaved' ) ).toBeHidden();
	const root = mkdtempSync( resolve( 'examples/astro/.integration-' ) );
	const file = `tests/e2e/.auth/integration-code/${ randomUUID() }.html`;
	let context;
	try {
		mkdirSync( `${ root }/src/components`, { recursive: true } );
		mkdirSync( `${ root }/src/pages`, { recursive: true } );
		writeFileSync( `${ root }/src/components/FormsteadForm.astro`, source );
		writeFileSync( `${ root }/src/pages/index.astro`, '---\nimport FormsteadForm from "../components/FormsteadForm.astro";\n---\n<html lang="en"><title>Generated Astro</title><body><main><h1>Contact</h1><FormsteadForm idPrefix="first" /><FormsteadForm idPrefix="second" /></main></body></html>' );
		execFileSync( 'node', [ 'examples/astro/node_modules/astro/bin/astro.mjs', 'build', '--root', root ], { stdio: 'pipe' } );
		mkdirSync( 'tests/e2e/.auth/integration-code', { recursive: true } );
		writeFileSync( file, readFileSync( `${ root }/dist/index.html` ) );
		context = await browser.newContext( { javaScriptEnabled: false, storageState: { cookies: [], origins: [] } } );
		const frontend = await context.newPage();
		await frontend.goto( `http://localhost:4400/generated/${ file.split( '/' ).pop() }` );
		await expect( frontend.locator( 'script' ) ).toHaveCount( 0 );
		const ids = await frontend.locator( '[id]' ).evaluateAll( elements => elements.map( element => element.id ) );
		expect( new Set( ids ).size ).toBe( ids.length );
		const fixture = JSON.parse( readFileSync( 'tests/fixtures/integration-code/all-fields.json', 'utf8' ) );
		await expect( frontend.locator( 'label[for="first-name"]' ) ).toHaveText( fixture.form_input.fields[0].label );
		const form = frontend.locator( 'form#first' );
		for ( const [ name, value ] of Object.entries( fixture.submission ) as [string, string | string[]][] ) {
			if ( name.startsWith( '_fs_' ) ) { continue; }
			const control = form.locator( `[name="${ name }${ Array.isArray( value ) ? '[]' : '' }"]` );
			if ( name === 'agree' ) { await control.check(); }
			else if ( Array.isArray( value ) || name === 'topic' || name === 'optional_topic' ) { await control.selectOption( value ); }
			else { await control.fill( value ); }
		}
		await form.getByRole( 'button', { name: 'Send', exact: true } ).click();
		await expect( frontend ).toHaveURL( 'http://localhost:4400/thanks.html' );
		expect( formState( saved.public_id ).submissions ).toBe( 1 );
		expect( wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', String( saved.id ) ] ) ).toEqual( fixture.normalized );
	} finally {
		await context?.close();
		rmSync( root, { recursive: true, force: true } );
		rmSync( file, { force: true } );
	}
} );
