import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { formState, wpJson } from './helpers/wp';

const fixture = JSON.parse( readFileSync( 'tests/fixtures/integration-code/all-fields.json', 'utf8' ) ) as {
	submission: Record<string, string | string[]>;
	normalized: Record<string, string | string[] | boolean>;
};

test( 'HTML copied from the editor submits all field types without JavaScript', async ( { page, browser } ) => {
	const form = wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php' ] ) as { id: number; public_id: string };
	await page.goto( `/wp-admin/admin.php?page=formstead-forms&form=${ form.id }` );
	const code = await page.locator( '#formstead-integration-html' ).inputValue();
	expect( code ).toContain( form.public_id );
	await expect( page.locator( 'form.formstead-form' ) ).toHaveCount( 0 );
	const adminA11y = await new AxeBuilder( { page } ).include( 'section[aria-labelledby="formstead-integration-heading"]' ).analyze();
	expect( adminA11y.violations ).toEqual( [] );

	const context = await browser.newContext( { javaScriptEnabled: false, storageState: { cookies: [], origins: [] } } );
	try {
		const frontend = await context.newPage();
		// Serve exactly the code read from the panel at the allowed HTTP origin.
		await frontend.route( 'http://localhost:4400/generated-integration.html', ( route ) => route.fulfill( {
			contentType: 'text/html; charset=utf-8',
			body: `<!doctype html><html lang="en"><title>Integration test</title><body><main><h1>Contact</h1>${ code }</main></body></html>`,
		} ) );
		await frontend.goto( 'http://localhost:4400/generated-integration.html' );
		await expect( frontend.locator( 'script' ) ).toHaveCount( 0 );
		for ( const [ name, value ] of Object.entries( fixture.submission ) ) {
			if ( name.startsWith( '_fs_' ) ) {
				continue;
			}
			const control = frontend.locator( `[name="${ name }${ Array.isArray( value ) ? '[]' : '' }"]` );
			if ( name === 'agree' ) {
				await control.check();
			} else if ( Array.isArray( value ) || name === 'topic' || name === 'optional_topic' ) {
				await control.selectOption( value );
			} else {
				await control.fill( value );
			}
		}
		const sent = frontend.waitForResponse( ( response ) => response.request().method() === 'POST' && response.url().includes( form.public_id ) );
		await frontend.getByRole( 'button', { name: 'Send', exact: true } ).click();
		expect( ( await sent ).status() ).toBe( 303 );
		await expect( frontend ).toHaveURL( 'http://localhost:4400/thanks.html' );
		expect( formState( form.public_id ).submissions ).toBe( 1 );
		expect( wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php', String( form.id ) ] ) ).toEqual( fixture.normalized );
	} finally {
		await context.close();
	}
} );

async function openEditor( page: Page ): Promise<number> {
	const form = wpJson( [ 'eval-file', 'tests/e2e/fixtures/integration-code.php' ] ) as { id: number };
	await page.goto( `/wp-admin/admin.php?page=formstead-forms&form=${ form.id }` );
	return form.id;
}

test( 'keyboard copying writes the visible source without saving invalid edits', async ( { page } ) => {
	await page.addInitScript( () => {
		Object.defineProperty( navigator, 'clipboard', { configurable: true, value: {
			writeText: async ( text: string ) => { Object.assign( window, { formsteadCopied: text } ); },
		} } );
	} );
	await openEditor( page );
	const dirty = page.locator( '#formstead-integration-unsaved' );
	const code = await page.locator( '#formstead-integration-html' ).inputValue();
	await expect( dirty ).toBeHidden();
	const posts: string[] = [];
	page.on( 'request', ( request ) => { if ( request.method() === 'POST' ) { posts.push( request.url() ); } } );
	await page.locator( '#formstead-name' ).fill( '' );
	await expect( dirty ).toBeVisible();
	const button = page.getByRole( 'button', { name: 'Copy code', exact: true } );
	await button.focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#formstead-copy-status' ) ).toHaveText( 'Copied.' );
	expect( await page.evaluate( () => ( window as Window & { formsteadCopied: string } ).formsteadCopied ) ).toBe( code );
	await expect( button ).toBeFocused();
	expect( posts ).toEqual( [] );
	await page.reload();
	await expect( dirty ).toBeHidden();
	await expect( page.locator( '#formstead-name' ) ).toHaveValue( 'Integration contract — all fields' );
	await expect( page.locator( '#formstead-integration-html' ) ).toHaveValue( code );
} );

for ( const mode of [ 'missing', 'denied' ] as const ) {
	test( `clipboard ${ mode } selects all code and announces the manual fallback on HTTP`, async ( { page } ) => {
		await page.addInitScript( ( failure ) => {
			Object.defineProperty( navigator, 'clipboard', { configurable: true, value: failure === 'missing' ? undefined : {
				writeText: async () => { throw new DOMException( 'Denied', 'NotAllowedError' ); },
			} } );
		}, mode );
		await openEditor( page );
		expect( new URL( page.url() ).protocol ).toBe( 'http:' );
		await page.getByRole( 'button', { name: 'Copy code', exact: true } ).click();
		await expect( page.locator( '#formstead-copy-status' ) ).toHaveText( 'Press Ctrl+C or Cmd+C to copy.' );
		const code = page.locator( '#formstead-integration-html' );
		await expect( code ).toBeFocused();
		expect( await code.evaluate( ( element: HTMLTextAreaElement ) => [ element.selectionStart, element.selectionEnd ] ) ).toEqual( [ 0, ( await code.inputValue() ).length ] );
		await expect( page.locator( '#formstead-integration-unsaved' ) ).toBeHidden();
		await expect( page.getByRole( 'button', { name: 'Copy code', exact: true } ) ).toBeEnabled();
		const a11y = await new AxeBuilder( { page } ).include( 'section[aria-labelledby="formstead-integration-heading"]' ).analyze();
		expect( a11y.violations ).toEqual( [] );
	} );
}

test( 'editing controls and adding or removing rows warns without changing generated code', async ( { page } ) => {
	await openEditor( page );
	const code = await page.locator( '#formstead-integration-html' ).inputValue();
	const dirty = page.locator( '#formstead-integration-unsaved' );
	await expect( dirty ).toBeHidden();
	await page.locator( '#formstead-add-field' ).click();
	await expect( dirty ).toBeVisible();
	await page.reload();
	await expect( dirty ).toBeHidden();
	await page.locator( '[data-field-row]' ).last().getByRole( 'button', { name: 'Remove', exact: true } ).click();
	await expect( dirty ).toBeVisible();
	await expect( page.locator( '#formstead-integration-html' ) ).toHaveValue( code );
	await page.reload();
	await expect( dirty ).toBeHidden();
	await page.locator( '#formstead-enabled' ).uncheck();
	await expect( dirty ).toBeVisible();
	await expect( page.locator( '#formstead-integration-html' ) ).toHaveValue( code );
} );

test( 'failed validation keeps the saved code and unsaved notice after the server response', async ( { page } ) => {
	await openEditor( page );
	const code = await page.locator( '#formstead-integration-html' ).inputValue();
	await page.locator( '#formstead-name' ).fill( '' );
	await page.locator( '#formstead-form-editor' ).evaluate( ( form: HTMLFormElement ) => { form.noValidate = true; } );
	await page.getByRole( 'button', { name: 'Save form', exact: true } ).click();
	await expect( page.locator( '#formstead-integration-unsaved' ) ).toBeVisible();
	await expect( page.locator( '#formstead-name' ) ).toHaveValue( '' );
	await expect( page.locator( '#formstead-integration-html' ) ).toHaveValue( code );
	await expect( page.getByRole( 'button', { name: 'Copy code', exact: true } ) ).toBeVisible();
} );

test( 'without panel JavaScript code remains selectable and editor saves still work', async ( { browser } ) => {
	const context = await browser.newContext( { javaScriptEnabled: false, storageState: 'tests/e2e/.auth/admin.json' } );
	try {
		const page = await context.newPage();
		const id = await openEditor( page );
		const code = page.locator( '#formstead-integration-html' );
		await expect( code ).toBeVisible();
		await expect( page.locator( '#formstead-integration-javascript' ) ).toBeVisible();
		await expect( page.locator( '#formstead-platform-controls' ) ).toBeHidden();
		await expect( page.getByRole( 'button', { name: 'Copy code', exact: true } ) ).toBeHidden();
		await code.focus();
		await page.keyboard.press( 'ControlOrMeta+A' );
		expect( await code.evaluate( ( element: HTMLTextAreaElement ) => [ element.selectionStart, element.selectionEnd ] ) ).toEqual( [ 0, ( await code.inputValue() ).length ] );
		await page.locator( '#formstead-fields [name="fields[0][label]"]' ).fill( 'Updated name label' );
		await page.getByRole( 'button', { name: 'Save form', exact: true } ).click();
		await expect( page ).toHaveURL( new RegExp( `form=${ id }&updated=1` ) );
		await expect( code ).toContainText( 'Updated name label' );
	} finally {
		await context.close();
	}
} );

test( 'platform choice changes the copied code without dirtying or saving the editor', async ( { page } ) => {
	await page.addInitScript( () => {
		Object.defineProperty( navigator, 'clipboard', { value: {
			writeText: async ( text: string ) => { Object.assign( window, { formsteadCopied: text } ); },
		} } );
	} );
	await openEditor( page );
	const posts: string[] = [];
	page.on( 'request', request => { if ( request.method() === 'POST' ) { posts.push( request.url() ); } } );
	await page.locator( '#formstead-integration-platform' ).selectOption( 'javascript' );
	await expect( page.locator( '#formstead-integration-html' ) ).toBeHidden();
	await expect( page.locator( '#formstead-integration-javascript' ) ).toBeVisible();
	const source = await page.locator( '#formstead-integration-javascript' ).inputValue();
	expect( source ).toContain( '<script>' );
	await page.getByRole( 'button', { name: 'Copy code', exact: true } ).click();
	await expect( page.locator( '#formstead-copy-status' ) ).toHaveText( 'Copied.' );
	expect( await page.evaluate( () => ( window as Window & { formsteadCopied: string } ).formsteadCopied ) ).toBe( source );
	await expect( page.locator( '#formstead-integration-unsaved' ) ).toBeHidden();
	expect( posts ).toEqual( [] );
	await page.locator( '#formstead-integration-platform' ).selectOption( 'html' );
	await expect( page.locator( '#formstead-integration-html' ) ).toBeVisible();
	await expect( page.locator( '#formstead-integration-javascript' ) ).toBeHidden();
	await expect( page.locator( '#formstead-copy-status' ) ).toBeEmpty();
} );
