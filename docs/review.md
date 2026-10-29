# Author's review

This review was prepared by the plugin author with assistance from a language model. It is the author's review of version `1.0.0-rc.1`, commit `63c8ed8bf27b74457315a18baf35f550f83352f6`. There has been no independent security audit. That statement is also kept in the [README](../README.md), the [threat model](threat-model.md), and the [testing guide](testing.md). This page does not replace it.

Report a vulnerability to security@formstead.dev. See [SECURITY.md](../SECURITY.md).

## What was read

The README, the threat model, the data-flow note, and the API contract, then the code paths those documents describe and a selection of regression tests. The same pass included logging, retention, uninstall, dependency configuration, and the manifest of the local package. The working tree was clean. The package manifest did not contain tests, examples, notes, or development configuration. The files searched did not contain an obvious embedded secret.

The review did not run the project or its tests. It did not query vulnerability databases, and it did not compare every file in a ZIP with that commit. It did not use a production site, an external endpoint, or real personal data.

## Result

No confirmed vulnerability met the evidence standard in the paths that were read. That is not a claim that the plugin, WordPress, or a host is free of defects.

- A public submission is checked against the form schema. Unknown fields are not stored. The request size, the field count, and the JSON depth are limited. The response does not return the stored submission.
- Reading, a status change, export, deletion, and settings each check a capability. Administrative REST routes set `permission_callback` to `__return_true` so a missing cookie nonce does not block an application password. Each handler then performs the access check. No bypass of that check was found.
- Query values go through prepared statements. Admin views escape text and attributes. CSV export prefixes cells that could be read as formulas. The public success redirect uses the URL saved on the form.
- A missing `Origin` is allowed on the public route. It does not open administration or stored submissions.
- A webhook address is checked when it is saved and again before each send. Private and local addresses are refused. Redirects are not followed. Delivery requires cURL address pinning. The person who submits the form does not choose the recipients or the webhook URL.
- An idempotency key is unique for its form and is claimed in a transaction. A worker claims a delivery with one atomic update. Deleting a submission removes the related index rows and idempotency keys and cancels an open delivery.

## After the review

Version 1.0.0 closes a delivery whose worker lock has expired after every attempt has been used. Recovery does not send that delivery again. That close was not part of the reviewed commit.

A hosting pilot, recorded in the [threat model](threat-model.md), exercised a submission from another site, mail delivery, a signed webhook, the submissions API from an administrator session, and personal-data export and erasure. The pilot is not an independent audit. The regression suite is described in the [testing guide](testing.md).
