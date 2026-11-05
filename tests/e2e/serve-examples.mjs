/**
 * Serves the HTML examples and substitutes the seeded endpoint.
 *
 * `?form=same|cross|closed|rate` selects which submissions URL replaces
 * `__FORMSTEAD_ENDPOINT__`. The default is the cross-origin form.
 */
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { dirname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const examplesDir = join( root, 'examples/html' );
const formsPath = join( root, 'tests/e2e/.auth/forms.json' );
const formKeys = [ 'same', 'cross', 'closed', 'rate' ];

/**
 * @param {string} value
 * @return {string}
 */
function escapeAttribute( value ) {
	return value
		.replaceAll( '&', '&amp;' )
		.replaceAll( '"', '&quot;' )
		.replaceAll( '<', '&lt;' );
}

/**
 * @param {string} key
 * @return {string|null}
 */
function endpointFor( key ) {
	const stored = JSON.parse( readFileSync( formsPath, 'utf8' ) );
	const form = stored?.forms?.[ key ];
	return typeof form?.endpoint === 'string' ? form.endpoint : null;
}

const server = createServer( ( request, response ) => {
	const url = new URL( request.url ?? '/', 'http://127.0.0.1:4400' );

	if ( url.pathname === '/health' ) {
		response.writeHead( 200, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'ok' );
		return;
	}

	// Only test-created UUID filenames are served; never accept an arbitrary path.
	const generated = /^\/generated\/([a-f0-9-]{36})\.html$/.exec( url.pathname );
	if ( generated ) {
		try {
			const html = readFileSync( join( root, 'tests/e2e/.auth/integration-code', generated[ 1 ] + '.html' ), 'utf8' );
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( html );
		} catch {
			response.writeHead( 404 );
			response.end( 'Generated fixture not found.' );
		}
		return;
	}

	const relative = normalize( decodeURIComponent( url.pathname ) ).replace( /^[/\\]+/, '' );
	if ( relative === '' || relative.includes( '..' ) || ! relative.endsWith( '.html' ) ) {
		response.writeHead( 404, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'not found' );
		return;
	}

	const key = url.searchParams.get( 'form' ) ?? 'cross';
	if ( ! formKeys.includes( key ) ) {
		response.writeHead( 400, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'Unknown form.' );
		return;
	}

	let html;
	try {
		html = readFileSync( join( examplesDir, relative ), 'utf8' );
	} catch {
		response.writeHead( 404, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'not found' );
		return;
	}

	const endpoint = endpointFor( key );
	if ( endpoint === null ) {
		response.writeHead( 500, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'Seeded forms are missing.' );
		return;
	}

	response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
	response.end( html.replaceAll( '__FORMSTEAD_ENDPOINT__', escapeAttribute( endpoint ) ) );
} );

server.listen( 4400, '127.0.0.1' );
