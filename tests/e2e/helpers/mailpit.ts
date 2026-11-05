import { execFileSync } from 'node:child_process';

/** Refuse destructive inbox operations unless the port belongs to our container. */
export function assertTestMailbox( port: string ): void {
	let ports: Record<string, { HostPort: string }[]>;
	try {
		ports = JSON.parse( execFileSync( 'docker', [
			'inspect', '--format', '{{json .NetworkSettings.Ports}}', 'formstead-mailpit',
		], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ) );
	} catch {
		throw new Error( 'Start the Formstead Mailpit container before running mail tests. Refusing to clear another mailbox.' );
	}
	if ( ! ports['8025/tcp']?.some( binding => binding.HostPort === port ) ) {
		throw new Error( `Port ${ port } does not belong to Formstead Mailpit. Refusing to clear another mailbox.` );
	}
}
