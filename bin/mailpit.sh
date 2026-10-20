#!/usr/bin/env bash
# Start or stop the local Mailpit container and attach it to the wp-env network.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

container_name="formstead-mailpit"

wordpress_container() {
	docker ps --format '{{.Names}}' | grep -E -- '-wordpress-1$' | grep -v -- '-tests-wordpress-1$' | head -n 1
}

network_of() {
	docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$1" | head -n 1
}

ui_port() {
	echo "${FORMSTEAD_MAILPIT_UI_PORT:-8025}"
}

wait_for_inbox() {
	local attempt
	for attempt in $(seq 1 30); do
		if curl -sf -o /dev/null "http://127.0.0.1:$(ui_port)/api/v1/info"; then
			return 0
		fi
		sleep 0.5
	done

	echo "Mailpit did not open http://127.0.0.1:$(ui_port)." >&2
	exit 1
}

up() {
	docker compose up -d

	local wordpress network
	wordpress="$(wordpress_container || true)"
	if [[ -z "$wordpress" ]]; then
		echo "The wp-env WordPress container is not running. Start it with npx wp-env start." >&2
		exit 1
	fi

	network="$(network_of "$wordpress")"
	if [[ -z "$network" ]]; then
		echo "Could not read the network of ${wordpress}." >&2
		exit 1
	fi

	if ! docker inspect -f '{{json .NetworkSettings.Networks}}' "$container_name" | grep -q "\"${network}\""; then
		docker network connect "$network" "$container_name"
	fi

	wait_for_inbox
	echo "Mailpit is ${container_name} on ${network}, SMTP port 1025. Inbox: http://localhost:$(ui_port)"
}

down() {
	docker compose down
}

case "${1:-}" in
	up) up ;;
	down) down ;;
	*)
		echo "Usage: bin/mailpit.sh up|down" >&2
		exit 1
		;;
esac
