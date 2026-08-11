# ZuidWest Knabbel

Publishes selected WordPress posts as radio stories in the [Babbel API](https://github.com/oszuidwest/zwfm-babbel), with speech text generated through the WordPress AI Client.

## What it does

- adds a native **Radionieuws** metabox to the post editor
- generates provider-independent speech text through the WordPress AI Client
- creates, updates, deletes, and restores the matching Babbel story
- learns from editor-corrected Babbel text through nightly few-shot synchronization
- processes publication work asynchronously with Action Scheduler

## Requirements

- WordPress 7.0+
- PHP 8.3 or 8.4
- A compatible AI provider configured under **Settings > Connectors**
- A running instance of the [Babbel API](https://github.com/oszuidwest/zwfm-babbel)

## Setup

1. Install the latest ZIP from [GitHub Releases](https://github.com/oszuidwest/zw-knabbel-wp/releases).
2. Activate the plugin and configure an AI provider under **Settings > Connectors**.
3. Configure Babbel and story defaults under **Settings > ZuidWest Knabbel**.
4. Enable **Radionieuws** on a post; publishing queues the story for generation and delivery.

## Development

```bash
composer install
npm install
vendor/bin/phpcs
vendor/bin/phpstan analyse
npm run lint
shellcheck tests/e2e/run.sh
```

Run the isolated WordPress 7.0.2 and Babbel regression suite with:

```bash
BABBEL_PATH=../zwfm-babbel tests/e2e/run.sh
```

## Translations

```bash
wp i18n make-pot . languages/zw-knabbel-wp.pot --slug=zw-knabbel-wp --domain=zw-knabbel-wp
msgfmt -o languages/zw-knabbel-wp-nl_NL.mo languages/zw-knabbel-wp-nl_NL.po
```

## Release

The release workflow reads the version from `zw-knabbel-wp.php`, builds `zw-knabbel-wp-{version}.zip`, and attaches it to the GitHub Release.

## License

MIT
