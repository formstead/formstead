# HTML examples

Two ways to post a form without JavaScript. Copy the submissions URL from the form screen in WordPress. That address is public. It is not a secret. With plain permalinks it looks like `/?rest_route=/formstead/v1/forms/fs_…/submissions`.

Replace `__FORMSTEAD_ENDPOINT__` with that URL.

## Same site

`same-site.html` is a fragment for a Custom HTML block on the WordPress site. Leave the form's origin allowlist empty. An empty allowlist accepts only that site.

## Another origin

`cross-origin.html` is a full page. Add its origin to the form allowlist, including the scheme, host, and port. `http://localhost:4400` and `http://127.0.0.1:4400` are different origins. `thanks.html` is a page you can use as the form's success URL.

A browser sends an `Origin` header on this POST. A request with no `Origin` header is allowed and still counts toward the rate limit. That is the path for a server-side client. `Origin: null` is refused.

## Fields

`email` and `message` must be the field names saved on the form. The browser does not decide whether a value is accepted. An empty required field comes back as an HTML page that names the field and does not repeat what was typed.

Leave `_fs_honeypot` empty and out of the tab order. A value in that field looks like success and is not stored.
