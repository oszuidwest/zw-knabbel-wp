#!/usr/bin/env bash

set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd "$script_dir/../.." && pwd)
babbel_path=${BABBEL_PATH:-}

if [[ -z "$babbel_path" ]]; then
	echo "BABBEL_PATH must point to a zwfm-babbel checkout." >&2
	exit 2
fi

babbel_path=$(cd "$babbel_path" && pwd)
if [[ ! -f "$babbel_path/Dockerfile" || ! -f "$babbel_path/migrations/001_complete_schema.sql" ]]; then
	echo "BABBEL_PATH is not a usable zwfm-babbel checkout: $babbel_path" >&2
	exit 2
fi

if [[ ! -f "$repo_root/vendor/autoload.php" ]]; then
	echo "Composer dependencies are missing. Run composer install first." >&2
	exit 2
fi

command -v docker >/dev/null 2>&1 || {
	echo "Docker is required for the E2E suite." >&2
	exit 2
}

project_name=${COMPOSE_PROJECT_NAME:-zw-knabbel-e2e-$$}
artifact_dir="$script_dir/artifacts"
export BABBEL_PATH="$babbel_path"

compose=(docker compose --project-name "$project_name" --file "$script_dir/compose.yml")

cleanup() {
	status=$?
	trap - EXIT

	if ((status != 0)); then
		mkdir -p "$artifact_dir"
		"${compose[@]}" ps --all || true
		"${compose[@]}" logs --no-color >"$artifact_dir/docker.log" 2>&1 || true
		tail -n 300 "$artifact_dir/docker.log" || true
	fi

	"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT

wait_for_service() {
	local service=$1
	local container_id
	local status

	container_id=$("${compose[@]}" ps --quiet "$service")
	if [[ -z "$container_id" ]]; then
		echo "Service did not start: $service" >&2
		return 1
	fi

	for _ in {1..90}; do
		status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")
		if [[ "$status" == "healthy" ]]; then
			return 0
		fi
		if [[ "$status" == "unhealthy" || "$status" == "exited" || "$status" == "dead" ]]; then
			echo "Service $service entered state $status." >&2
			return 1
		fi
		sleep 2
	done

	echo "Timed out waiting for service $service." >&2
	return 1
}

wp() {
	"${compose[@]}" run --rm --no-deps wordpress-cli wp "$@"
}

rm -rf "$artifact_dir"

echo "Starting isolated WordPress and Babbel services..."
"${compose[@]}" up --detach --build wordpress-db babbel-db babbel wordpress
wait_for_service wordpress-db
wait_for_service babbel-db
wait_for_service babbel
wait_for_service wordpress

echo "Installing WordPress..."
wp core install \
	--url=http://wordpress \
	--title='Knabbel E2E' \
	--admin_user=admin \
	--admin_password=e2e-admin-password \
	--admin_email=e2e@example.test \
	--skip-email
wp option update timezone_string Europe/Amsterdam
wp plugin activate zw-knabbel-wp

echo "Running E2E regression suite..."
wp eval 'require "/var/www/html/wp-content/plugins/zw-knabbel-wp/tests/e2e/suite.php";'
