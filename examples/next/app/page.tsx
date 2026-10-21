import { ContactForm } from '../components/ContactForm';

export const dynamic = 'force-dynamic';

export default function Page() {
	const endpoint = process.env.FORMSTEAD_ENDPOINT;
	if ( ! endpoint ) {
		throw new Error( 'Set FORMSTEAD_ENDPOINT to the submissions URL from the form screen.' );
	}

	return (
		<main>
			<h1>Contact</h1>
			<ContactForm action={ endpoint } />
		</main>
	);
}
