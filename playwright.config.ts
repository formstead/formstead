import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';

function baseURL(): string {
	try {
		const forms = JSON.parse( readFileSync( 'tests/e2e/.auth/forms.json', 'utf8' ) ) as {
			base_url?: string;
		};
		if ( forms.base_url ) {
			return forms.base_url;
		}
	} catch {
		// Setup has not written the file yet. The tests site port is stable.
	}

	return 'http://localhost:8889';
}

export default defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	globalSetup: './tests/e2e/global-setup.ts',
	use: {
		baseURL: baseURL(),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		storageState: 'tests/e2e/.auth/admin.json',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	webServer: [
		{
			command: 'node tests/e2e/serve-examples.mjs',
			url: 'http://127.0.0.1:4400/health',
			reuseExistingServer: ! process.env.CI,
		},
		{
			command: 'npm run preview --prefix examples/astro',
			url: 'http://127.0.0.1:4321/',
			reuseExistingServer: ! process.env.CI,
		},
		{
			command: 'node tests/e2e/serve-next.mjs',
			url: 'http://127.0.0.1:4500/',
			reuseExistingServer: ! process.env.CI,
		},
	],
} );
