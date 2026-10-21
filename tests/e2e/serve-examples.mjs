/**
 * Health endpoint for the example server.
 *
 * The HTML files and the endpoint substitution arrive with the HTML examples.
 * This process only has to accept connections so Playwright can start.
 */
import { createServer } from 'node:http';

const server = createServer( ( request, response ) => {
	const url = new URL( request.url ?? '/', 'http://127.0.0.1:4400' );
	if ( '/health' === url.pathname ) {
		response.writeHead( 200, { 'content-type': 'text/plain; charset=utf-8' } );
		response.end( 'ok' );
		return;
	}

	response.writeHead( 404, { 'content-type': 'text/plain; charset=utf-8' } );
	response.end( 'not found' );
} );

server.listen( 4400, '127.0.0.1' );
