#!/usr/bin/env bash
# Assert Mailpit stored one notification without the submitted value.
set -euo pipefail

ui_port="${1:-8025}"
secret="${2:-}"

if [[ -z "$secret" ]]; then
	echo "Usage: bin/ci/assert-one-mailpit-message.sh <ui-port> <secret>" >&2
	exit 1
fi

list="$(curl -sf "http://127.0.0.1:${ui_port}/api/v1/messages")"
message_id="$(printf '%s' "$list" | python3 -c '
import json, sys
data = json.load(sys.stdin)
messages = data.get("messages") or []
secret = sys.argv[1]
if data.get("total") != 1 or len(messages) != 1:
    print("Expected one Mailpit message.", file=sys.stderr)
    print(data, file=sys.stderr)
    sys.exit(1)
subject = str(messages[0].get("Subject") or "")
if secret in subject or "\n" in subject or "\r" in subject:
    print("Subject contains the submitted value or a line break.", file=sys.stderr)
    sys.exit(1)
if "Contact" not in subject:
    print("Subject did not include the form name.", file=sys.stderr)
    print(subject, file=sys.stderr)
    sys.exit(1)
sys.stdout.write(str(messages[0].get("ID") or ""))
' "$secret")"

message="$(curl -sf "http://127.0.0.1:${ui_port}/api/v1/message/${message_id}")"
printf '%s' "$message" | python3 -c '
import json, sys
data = json.load(sys.stdin)
text = str(data.get("Text") or "")
secret = sys.argv[1]
if secret in text:
    print("Message body included the submitted value.", file=sys.stderr)
    sys.exit(1)
if "page=formstead" not in text or "submission=" not in text:
    print("Message body did not link to the inbox.", file=sys.stderr)
    print(text, file=sys.stderr)
    sys.exit(1)
print("Mailpit stored one notification.")
' "$secret"
