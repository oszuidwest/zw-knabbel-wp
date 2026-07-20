# Babbel end-to-end tests

This suite validates the complete WordPress-to-Babbel synchronization flow in
isolated Docker containers. It runs WordPress 7.0.1 on PHP 8.3, a real Babbel
API built from a local checkout, separate MySQL databases, Action Scheduler,
and a deterministic WordPress HTTP stub for OpenAI.

## Run locally

Prerequisites:

- Docker with Docker Compose
- Composer dependencies installed in this plugin repository
- Node.js dependencies and the Playwright Chromium browser installed
- A local `zwfm-babbel` checkout

From the plugin root:

```bash
composer install
npm install
npx playwright install chromium
BABBEL_PATH=../zwfm-babbel tests/e2e/run.sh
```

`BABBEL_PATH` must contain Babbel's `Dockerfile` and
`migrations/001_complete_schema.sql`. The runner creates a unique Compose
project, removes it after the run, and writes combined container logs to
`tests/e2e/artifacts/docker.log` on failure.

## Covered scenarios

The scenario catalog (E2E-001 through E2E-011) lives in the `run()` method of
`suite.php`; the runner prints each scenario ID and title during execution.

Before the PHP scenarios, Playwright drives the real WordPress admin UI in
Chromium. The browser coverage saves and tests plugin settings, publishes and
edits a post, disables and restores Babbel synchronization, cancels a scheduled
post, and trashes and restores a sent post. The browser suite uses the classic
WordPress post editor to keep the plugin-owned metabox flow deterministic; the
test-only editor filter and queue controls are loaded as an isolated MU plugin.

The credentials in the Compose file and suite are isolated test fixtures only.
