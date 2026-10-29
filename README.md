# Formstead

Your forms. Your infrastructure.

Formstead is an open-source WordPress plugin that receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions, shows them in a private inbox, and handles permissions. The site frontend stays with its author.

License: [GPL-2.0-or-later](LICENSE).

## Status

`1.0.0` closes a delivery whose worker died after the last attempt, so recovery does not send it again. A hosting pilot of the release candidate exercised a submission from another site, mail delivery, a signed webhook, the submissions API from an administrator session, and personal-data export and erasure. There has been no independent security audit. The [author's review](docs/review.md) records what that pass covered. `0.9.0` ended Core beta. `0.7.0` was the technical MVP. [HTML](examples/html/README.md), [Astro](examples/astro/README.md), and [Next.js](examples/next/README.md) examples post to WordPress from this site and from another origin, with and without JavaScript. The Next.js example also posts from a route handler, which sends no `Origin`, and checks a webhook signature. [Integrations](docs/integrations.md) covers CORS, the admin API, and webhooks. The [threat model](docs/threat-model.md) describes the controls.

The plugin accepts URL-encoded, multipart, and JSON submissions. A client that sends `Accept: application/json` receives a receipt id. Other clients are redirected or shown an HTML page. Sending the same `Idempotency-Key` again returns that result for 24 hours. A different submission with the same key is refused. Parallel requests that share a key are retried after a database lock conflict. WordPress stores the submission against the field schema that was current at the time. People who can read submissions see an inbox: unread, archived, and spam are separate views, and the list filters by form and date. Opening a new submission marks it read. One form can be exported as CSV from the current view. A form can list the sites allowed to submit it, and it can email recipients after a submission is stored. The message links to that submission. Each form keeps submissions for a number of days, 30 by default. Zero keeps them until someone deletes them. Settings shows the last cleanup and warns when it is overdue. The plugin activates on WordPress 7.0+ with PHP 8.3+.

A form may send `_fs_elapsed`, the number of milliseconds since the form was shown. A value under three seconds, or the same content on the same form within ten minutes, is stored as spam and is not emailed. The visitor still receives the normal success response. Omitting the field, or posting without JavaScript, leaves the submission in the inbox.

Forms that already exist keep submissions for 30 days after this update. The first cleanup deletes older submissions. They keep an empty recipient list and do not start sending mail until recipients are saved on the form.

Mail is handed to the site's mail system through WP-Cron. A form can also POST a signed webhook to an HTTPS URL. A site with little traffic should run `wp formstead deliveries run` from system cron so a queued message or webhook is not left waiting for the next visit. The same site should run `wp formstead retention run` so the hourly cleanup is not left waiting for the next visit.

People who can read submissions can list and update them at `formstead/v1/submissions`, addressed by receipt id. Deleting one requires the delete capability. The call uses HTTPS and a WordPress application password. Reading a submission there does not mark it read.

- Public contact: hello@formstead.dev
- First form: [docs/getting-started.md](docs/getting-started.md)
- API contract: [docs/api.md](docs/api.md)
- Running the site: [docs/operations.md](docs/operations.md)
- Security reports: [SECURITY.md](SECURITY.md) (`security@formstead.dev`)
- Threat model: [docs/threat-model.md](docs/threat-model.md)
- Author's review: [docs/review.md](docs/review.md)
- Data flows: [docs/data-flows.md](docs/data-flows.md)
- Integrations: [docs/integrations.md](docs/integrations.md)
- How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changes: [CHANGELOG.md](CHANGELOG.md)

## Local development

Requires Docker and Node.js 22.

```sh
npm install
npx wp-env start
```

The development site is at http://localhost:8888 (`admin` / `password`).
