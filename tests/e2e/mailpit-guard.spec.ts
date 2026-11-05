import { expect, test } from '@playwright/test';
import { assertTestMailbox } from './helpers/mailpit';

test( 'mailbox cleanup accepts only the port mapped by the Formstead container', () => {
	const port = process.env.FORMSTEAD_MAILPIT_UI_PORT ?? '8025';
	expect( () => assertTestMailbox( port ) ).not.toThrow();
	// Port zero can never be a published Docker host port. No mailbox is deleted.
	expect( () => assertTestMailbox( '0' ) ).toThrow( 'Refusing to clear another mailbox.' );
} );
