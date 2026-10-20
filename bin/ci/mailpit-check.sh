#!/usr/bin/env bash
# Submit one form and confirm Mailpit stored a single message without the field value.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

bash bin/mailpit.sh up

seed_output="$(npx wp-env run cli --env-cwd=wp-content/plugins/formstead wp eval-file bin/ci/seed-notified-form.php)"
public_id="$(grep -Eo 'fs_[a-z2-7]{16}' <<<"$seed_output" | head -n 1 || true)"
if [[ ! "$public_id" =~ ^fs_[a-z2-7]{16}$ ]]; then
	echo "Seed did not print a public id." >&2
	printf '%s\n' "$seed_output" >&2
	exit 1
fi

endpoint_output="$(npx wp-env run cli wp eval "echo rest_url( 'formstead/v1/forms/${public_id}/submissions' );")"
endpoint="$(grep -Eo 'https?://[^[:space:]]+/wp-json/formstead/v1/forms/fs_[a-z2-7]{16}/submissions' <<<"$endpoint_output" | head -n 1 || true)"
if [[ -z "$endpoint" ]]; then
	echo "Could not read the submissions endpoint." >&2
	printf '%s\n' "$endpoint_output" >&2
	exit 1
fi
echo "endpoint:${endpoint}"

secret="mailpit-secret@example.com"
status="$(curl -s -o /tmp/formstead-mailpit-body -w '%{http_code}' \
	-H 'Content-Type: application/x-www-form-urlencoded' \
	--data "email=${secret}" \
	"$endpoint")"
echo "post:${status}"
if [[ "$status" != "303" ]]; then
	echo "Expected 303 from the notified form." >&2
	cat /tmp/formstead-mailpit-body >&2
	exit 1
fi

npx wp-env run cli --env-cwd=wp-content/plugins/formstead wp formstead deliveries run

ui_port="${FORMSTEAD_MAILPIT_UI_PORT:-8025}"
# The request can spawn WP-Cron, which may still be handing the message to Mailpit.
list=""
for _attempt in $(seq 1 20); do
	list="$(curl -sf "http://127.0.0.1:${ui_port}/api/v1/messages")"
	total="$(printf '%s' "$list" | node -e 'let raw=""; process.stdin.on("data",(c)=>{raw+=c}); process.stdin.on("end",()=>{ const data=JSON.parse(raw); process.stdout.write(String(data.total ?? "")); });')"
	if [[ "$total" != "0" ]]; then
		break
	fi
	sleep 0.5
done
message_id="$(printf '%s' "$list" | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => { raw += chunk; });
process.stdin.on("end", () => {
	const data = JSON.parse(raw);
	const messages = Array.isArray(data.messages) ? data.messages : [];
	if (data.total !== 1 || messages.length !== 1) {
		console.error("Expected one Mailpit message.");
		console.error(raw);
		process.exit(1);
	}
	const subject = String(messages[0].Subject || "");
	if (subject.includes("mailpit-secret@example.com") || subject.includes("\n") || subject.includes("\r")) {
		console.error("Subject contains the submitted value or a line break.");
		console.error(subject);
		process.exit(1);
	}
	if (!subject.includes("Contact")) {
		console.error("Subject did not include the form name.");
		console.error(subject);
		process.exit(1);
	}
	process.stdout.write(String(messages[0].ID || ""));
});
')"

message="$(curl -sf "http://127.0.0.1:${ui_port}/api/v1/message/${message_id}")"
printf '%s' "$message" | node -e '
let raw = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => { raw += chunk; });
process.stdin.on("end", () => {
	const data = JSON.parse(raw);
	const text = String(data.Text || "");
	if (text.includes("mailpit-secret@example.com")) {
		console.error("Message body included the submitted value.");
		process.exit(1);
	}
	if (!text.includes("page=formstead") || !text.includes("submission=")) {
		console.error("Message body did not link to the inbox.");
		console.error(text);
		process.exit(1);
	}
	console.log("Mailpit stored one notification.");
});
'
