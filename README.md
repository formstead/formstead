# Formstead

Your forms. Your infrastructure.

Formstead is an open-source WordPress plugin that receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions, shows them in a private inbox, and handles permissions. The site frontend stays with its author.

License: [GPL-2.0-or-later](LICENSE).

## Status

`0.3.1` accepts URL-encoded, multipart, and JSON submissions. A client that sends `Accept: application/json` receives a receipt id. Other clients are redirected or shown an HTML page. WordPress stores the submission against the field schema that was current at the time and lists it for people who can read submissions. A form can list the sites allowed to submit it. The plugin activates on WordPress 7.0+ with PHP 8.3+.

- Public contact: hello@formstead.dev
- Security reports: [SECURITY.md](SECURITY.md) (`security@formstead.dev`)
- How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changes: [CHANGELOG.md](CHANGELOG.md)

## Local development

Requires Docker and Node.js 22.

```sh
npm install
npx wp-env start
```

The development site is at http://localhost:8888 (`admin` / `password`).
