import { ContactForm } from '../../components/ContactForm';

export default function ServerPage() {
	return (
		<main>
			<h1>Contact</h1>
			<p>This page posts to the Next.js route, which sends the submission from the server.</p>
			<ContactForm action="/api/contact" />
		</main>
	);
}
