/** Capture publication screenshots from the local, synthetic tests site only. */
import { chromium } from '@playwright/test';
import { mkdir, readFile } from 'node:fs/promises';

const forms = JSON.parse( await readFile( 'tests/e2e/.auth/forms.json', 'utf8' ) );
const base = new URL( forms.base_url );
if ( ! [ 'localhost', '127.0.0.1' ].includes( base.hostname ) || base.port !== '8889' ) {
	throw new Error( 'Screenshots require the local tests site on port 8889.' );
}
await mkdir( '.wordpress-org', { recursive: true } );
const browser = await chromium.launch();
try {
	const context = await browser.newContext( {
		storageState: 'tests/e2e/.auth/admin.json',
		viewport: { width: 1280, height: 900 },
		deviceScaleFactor: 1,
	} );
	const page = await context.newPage();
	await page.goto( new URL( '/wp-admin/admin.php?page=formstead', base ).href );
	await page.getByRole( 'heading', { name: 'Submissions', exact: true } ).waitFor();
	await page.waitForTimeout( 1000 ); // Let WordPress view transitions finish before capture.
	await page.screenshot( { path: '.wordpress-org/screenshot-1.png', fullPage: true } );
	await page.goto( new URL( '/wp-admin/admin.php?page=formstead-forms', base ).href );
	await page.getByRole( 'link', { name: 'E2E same site', exact: true } ).click();
	await page.waitForTimeout( 1000 ); // Let WordPress view transitions finish before capture.
	await page.screenshot( {
		path: '.wordpress-org/screenshot-2.png',
		fullPage: true,
		mask: [ page.locator( 'input[name*="secret"]' ) ],
	} );
	await page.goto( new URL( '/wp-admin/admin.php?page=formstead-settings', base ).href );
	await page.getByRole( 'heading', { name: 'Settings', exact: true } ).waitFor();
	await page.waitForTimeout( 1000 ); // Let WordPress view transitions finish before capture.
	await page.screenshot( { path: '.wordpress-org/screenshot-3.png', fullPage: true } );
} finally {
	await browser.close();
}
