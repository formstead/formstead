# Your first form

Formstead needs a single WordPress site running WordPress 7.0 or later, PHP 8.3 or later, and InnoDB tables on MariaDB 10.11 or MySQL 8.0 or later. Use HTTPS for a deployed site. You do not need Composer or Node.js to install the ZIP. Webhooks also need PHP cURL with HTTPS support and direct outbound access to the destination.

## Install

1. Download the installable Formstead ZIP for the version you want to test.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**, select the ZIP and activate it. Network activation is refused.
3. Open **Formstead → Forms** and add a form. Give it a name and an absolute success URL on your frontend, such as `https://your-site.example/thanks`.
4. Add a required `email` field named `email` and a `textarea` named `message`. Save the form, open **Integration code**, choose **HTML**, **JavaScript / fetch**, **Astro** or **React / Next.js**, and use **Copy code**. The generated source contains your saved fields, endpoint and honeypot.
5. If the frontend has another origin, add its origin to the form's allowlist, including the scheme and any non-default port. An empty list accepts the WordPress site's own origin.

The public form id is not a password. Never put an application password or webhook secret in browser code.

Use HTML/fetch source where the form should appear. Save the Astro or React source
as a component and import it into a page; see the [component examples](integrations.md).
Adapt the labels and styling to your frontend.
Keep the fetch script immediately after its form. HTML redirects on success;
Astro posts natively too. JavaScript / fetch and React show confirmation on the same page and retain native POST as a fallback
when JavaScript is unavailable. If copying is refused, the code is selected for
Ctrl+C / Cmd+C. All four variants remain available for manual copying without panel JavaScript.

After editing fields, save and replace the source on your frontend. The generator
continues to use the last saved configuration while you edit. The public endpoint
remains stable. Copies are not synchronized automatically; the generator does not
deploy your frontend or supply its finished design. [Integrations](integrations.md) describes errors, retries and origins.

## Post without JavaScript

For a minimal manual integration, replace `COPIED_ENDPOINT` with the URL from
the form screen. You can also copy complete HTML from Integration code. This
example works with JavaScript disabled.

```html
<form method="post" action="COPIED_ENDPOINT">
  <label>Email <input type="email" name="email" required></label>
  <label>Message <textarea name="message"></textarea></label>
  <button type="submit">Send</button>
</form>
```

A valid submission redirects to the success URL with `303`. Invalid input gets an HTML error page. The success URL comes from the saved form settings. A value in the request cannot replace it.

Open **Formstead** to find the submission. Opening it marks it read. A success response means the submission was accepted; it does not mean a message was delivered to an inbox.

## Add delivery and cleanup

On the form, add notification recipients if you want email. Configure the site's mail service or SMTP plugin separately. Full submission content in email is an explicit option. For a webhook, follow [Integrations](integrations.md#webhooks), including signature verification at the receiver.

Check the form's retention before accepting real data: the default is 30 days; `0` keeps submissions until deletion, with the separate shorter spam policy still applying. Review the suggested privacy text in WordPress and adapt it to the site.

Run deliveries and retention from the host's scheduler when traffic is low. [Operations](operations.md) gives commands, permissions, update and uninstall instructions, and troubleshooting steps.

## Use another frontend

- [Plain HTML](../examples/html/README.md)
- [Astro](../examples/astro/README.md)
- [Next.js](../examples/next/README.md), including a server route and webhook verification
- [API contract](api.md), including JSON, errors, types and idempotency
- [Data flows](data-flows.md), including copies that remain outside Formstead

Examples live in the source repository, not in the installable ZIP. Local development uses HTTP at `http://localhost:8888`; the tests site uses `http://localhost:8889`.
