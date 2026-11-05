/**
 * Activate Formstead on the tests site, seed the four forms, and build the
 * examples. CI builds Next.js in its own step, so this skips that build there.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const jsonPrefix = 'FORMSTEAD_E2E_JSON ';

/**
 * @param {string[]} args Arguments after `wp`.
 * @return {string}
 */
function wp( args ) {
	return execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--config=.wp-env.tests.json', '--env-cwd=wp-content/plugins/formstead', 'wp', ...args ],
		{ encoding: 'utf8', cwd: root }
	);
}

/**
 * @param {string} output Combined wp-env output.
 * @return {object}
 */
function readSeed( output ) {
	const line = output
		.split( '\n' )
		.map( ( item ) => item.trim() )
		.find( ( item ) => item.startsWith( jsonPrefix ) );

	if ( ! line ) {
		process.stderr.write( output );
		throw new Error( 'Seed did not print FORMSTEAD_E2E_JSON.' );
	}

	return JSON.parse( line.slice( jsonPrefix.length ) );
}

wp( [ 'plugin', 'activate', 'formstead' ] );
wp( [ 'theme', 'activate', 'twentytwentyfive' ] );
wp( [
	'eval',
	'wp_set_password( "password", (int) get_user_by( "login", "admin" )->ID );',
] );

const seeded = readSeed( wp( [ 'eval-file', 'tests/e2e/fixtures/seed.php' ] ) );
const authDir = join( root, 'tests/e2e/.auth' );
mkdirSync( authDir, { recursive: true } );
writeFileSync( join( authDir, 'forms.json' ), JSON.stringify( seeded, null, 2 ) + '\n' );

if ( ! process.env.CI ) {
	const nextDir = join( root, 'examples/next' );
	if ( existsSync( join( nextDir, 'package.json' ) ) ) {
		execFileSync( 'npm', [ 'ci' ], { cwd: nextDir, stdio: 'inherit' } );
		execFileSync( 'npm', [ 'run', 'build' ], { cwd: nextDir, stdio: 'inherit' } );
	}
}

const astroDir = join( root, 'examples/astro' );
if ( existsSync( join( astroDir, 'package.json' ) ) ) {
	execFileSync( 'npm', [ 'ci' ], { cwd: astroDir, stdio: 'inherit' } );
	execFileSync( 'npm', [ 'run', 'build' ], {
		cwd: astroDir,
		stdio: 'inherit',
		env: {
			...process.env,
			PUBLIC_FORMSTEAD_ENDPOINT: seeded.forms.cross.endpoint,
			PUBLIC_FORMSTEAD_CLOSED_ENDPOINT: seeded.forms.closed.endpoint,
			PUBLIC_FORMSTEAD_RATE_ENDPOINT: seeded.forms.rate.endpoint,
			PUBLIC_FORMSTEAD_SAME_ENDPOINT: seeded.forms.same.endpoint,
		},
	} );
}

process.stdout.write( 'Seeded the wp-env tests site.\n' );
