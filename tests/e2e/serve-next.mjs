/**
 * Start the built Next.js example with the seeded submissions URL.
 */
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';

const forms = JSON.parse( readFileSync( 'tests/e2e/.auth/forms.json', 'utf8' ) );
const endpoint = forms?.forms?.cross?.endpoint;
if ( typeof endpoint !== 'string' || endpoint === '' ) {
	process.stderr.write( 'Seeded forms are missing.\n' );
	process.exit( 1 );
}

const child = spawn( 'npm', [ 'run', 'start', '--prefix', 'examples/next' ], {
	stdio: 'inherit',
	env: {
		...process.env,
		FORMSTEAD_ENDPOINT: endpoint,
	},
} );

child.on( 'exit', ( code ) => {
	process.exit( code ?? 1 );
} );
