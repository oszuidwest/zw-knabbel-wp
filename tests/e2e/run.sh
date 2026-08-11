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

if [[ ! -f "$repo_root/node_modules/@playwright/test/package.json" ]]; then
	echo "Playwright dependencies are missing. Run npm install first." >&2
	exit 2
fi

command -v docker >/dev/null 2>&1 || {
	echo "Docker is required for the E2E suite." >&2
	exit 2
}

project_name=${COMPOSE_PROJECT_NAME:-zw-knabbel-e2e-$$}
artifact_dir="$script_dir/artifacts"
wordpress_debug_log="$artifact_dir/wordpress-debug.log"
wordpress_php_errors="$artifact_dir/wordpress-php-errors.log"
export BABBEL_PATH="$babbel_path"

compose=(docker compose --project-name "$project_name" --file "$script_dir/compose.yml")

# Drain the container log into the artifact, so each phase only reports its own
# errors and a later collection can never clobber an earlier diagnostic.
collect_wordpress_debug_log() {
	if ! mkdir -p "$artifact_dir"; then
		echo "Could not create the E2E artifact directory." >&2
		return 1
	fi

	# The dollar-prefixed variables are evaluated by sh inside the container.
	# shellcheck disable=SC2016
	if ! "${compose[@]}" exec -T wordpress sh -eu -c \
		'log=/var/www/html/wp-content/debug.log; if [ -f "$log" ]; then cat "$log"; : >"$log"; fi' \
		>>"$wordpress_debug_log"; then
		echo "Could not collect the WordPress debug log." >&2
		return 1
	fi
}

assert_wordpress_debug_log_clean() {
	if ! collect_wordpress_debug_log; then
		return 1
	fi

	# WP_DEBUG can capture ambient core errors. Fail only when the origin points into this plugin.
	local grep_status=0
	grep -Ei 'PHP (warning|notice|deprecated|fatal error|parse error|recoverable fatal error).*/wp-content/plugins/zw-knabbel-wp/' \
		"$wordpress_debug_log" >"$wordpress_php_errors" || grep_status=$?

	if ((grep_status == 0)); then
		echo "The zw-knabbel-wp plugin emitted PHP errors:" >&2
		cat "$wordpress_php_errors" >&2
		return 1
	fi

	# grep exits 1 when there is simply nothing to report; anything above that is a
	# real failure and must never read as a clean log.
	if ((grep_status > 1)); then
		echo "Could not inspect the WordPress debug log." >&2
		return 1
	fi
}

cleanup() {
	status=$?
	trap - EXIT

	if ((status != 0)); then
		mkdir -p "$artifact_dir" || true
		"${compose[@]}" ps --all || true
		"${compose[@]}" logs --no-color >"$artifact_dir/docker.log" 2>&1 || true
		tail -n 300 "$artifact_dir/docker.log" || true
		collect_wordpress_debug_log || true
	fi

	"${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	exit "$status"
}
trap cleanup EXIT

wp() {
	"${compose[@]}" run --rm wordpress-cli wp "$@"
}

rm -rf "$artifact_dir"

echo "Starting isolated WordPress and Babbel services..."
"${compose[@]}" up --detach --build --wait --wait-timeout 240 babbel wordpress

wordpress_address=$("${compose[@]}" port wordpress 80)
babbel_address=$("${compose[@]}" port babbel 8080)
if [[ -z "$wordpress_address" || -z "$babbel_address" ]]; then
	echo "Docker Compose did not return the required published service ports." >&2
	exit 1
fi
wordpress_url="http://$wordpress_address"
babbel_url="http://$babbel_address/api/v1"

echo "Installing WordPress..."
"${compose[@]}" exec -T wordpress sh -eu -c \
	'mkdir -p /var/www/html/wp-content/uploads /var/www/html/wp-content/upgrade &&
	chown www-data:www-data /var/www/html/wp-content /var/www/html/wp-content/plugins /var/www/html/wp-content/uploads /var/www/html/wp-content/upgrade'
# Keep the admin fixture credentials in sync with tests/playwright/utils.ts.
wp core install \
	--url="$wordpress_url" \
	--title='Knabbel E2E' \
	--admin_user=admin \
	--admin_password=e2e-admin-password \
	--admin_email=e2e@example.test \
	--locale=en_US \
	--skip-email
wp option update timezone_string Europe/Amsterdam
wp plugin install ai-provider-for-openai --version=1.0.3 --activate
wp plugin install classic-editor --activate
wp plugin activate zw-knabbel-wp
wp option update connectors_ai_openai_api_key e2e-openai-key
# The dollar-prefixed variables are evaluated by PHP inside the container.
# shellcheck disable=SC2016
wp eval '$settings = get_option( "knabbel_settings", array() ); $settings["title_prompt"] = "legacy"; $settings["openai_api_key"] = "legacy-secret"; $settings["openai_model"] = "legacy-model"; update_option( "knabbel_settings", $settings );'
# shellcheck disable=SC2016
wp eval '$settings = get_option( "knabbel_settings", array() ); if ( array_intersect( array( "title_prompt", "openai_api_key", "openai_model" ), array_keys( $settings ) ) ) { throw new RuntimeException( "Legacy AI settings were not removed on init." ); } if ( 1 !== ( $settings["start_days_offset"] ?? null ) || "draft" !== ( $settings["default_status"] ?? null ) ) { throw new RuntimeException( "Plugin activation defaults are incorrect." ); }'

echo "Running browser E2E scenarios..."
PLAYWRIGHT_BASE_URL="$wordpress_url" \
	PLAYWRIGHT_BABBEL_URL="$babbel_url" \
	npm --prefix "$repo_root" run test:e2e:browser
assert_wordpress_debug_log_clean

echo "Running E2E regression suite..."
wp eval 'require "/var/www/html/wp-content/plugins/zw-knabbel-wp/tests/e2e/suite.php";'
assert_wordpress_debug_log_clean
