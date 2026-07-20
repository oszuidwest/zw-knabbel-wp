# Babbel end-to-end tests

This suite validates the complete WordPress-to-Babbel synchronization flow in
isolated Docker containers. It runs WordPress 7.0.1 on PHP 8.3, a real Babbel
API built from a local checkout, separate MySQL databases, Action Scheduler,
and a deterministic WordPress HTTP stub for OpenAI.

## Run locally

Prerequisites:

- Docker with Docker Compose
- Composer dependencies installed in this plugin repository
- A local `zwfm-babbel` checkout

From the plugin root:

```bash
composer install
BABBEL_PATH=../zwfm-babbel tests/e2e/run.sh
```

`BABBEL_PATH` must contain Babbel's `Dockerfile` and
`migrations/001_complete_schema.sql`. The runner creates a unique Compose
project, removes it after the run, and writes combined container logs to
`tests/e2e/artifacts/docker.log` on failure.

## Covered scenarios

The scenario catalog (E2E-001 through E2E-011) lives in the `run()` method of
`suite.php`; the runner prints each scenario ID and title during execution.

The credentials in the Compose file and suite are isolated test fixtures only.
