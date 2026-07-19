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

The regression suite checks:

1. Plugin activation, recurring Action Scheduler setup, Babbel login, session
   caching, and one-time recovery from an invalid session.
2. Published story creation, payload fidelity, persisted state, and send-once
   behavior.
3. Selective title/content synchronization and recovery from invalid Babbel
   credentials without leaking secrets.
4. Checkbox-driven soft delete and restore.
5. Scheduled, rescheduled, and published date calculations.
6. Cancellation when a scheduled post returns to draft.
7. Trash and untrash delete/restore behavior.
8. OpenAI retry exhaustion without a remote side effect.
9. Babbel create failure diagnostics.
10. Few-shot synchronization of editor-corrected speech text and disabling the
    cache.
11. Deactivation cleanup of sessions, cached examples, and scheduled actions.

The credentials in the Compose file and suite are isolated test fixtures only.
