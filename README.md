# ZuidWest Knabbel

WordPress plugin that automatically sends posts to the [Babbel API](https://github.com/oszuidwest/zwfm-babbel) for radio news.

## Features

- **ACF Integration**: Injects a "Radio News" checkbox into a specific ACF field group
- **OpenAI Integration**: Converts article text to radio-friendly speech text using GPT models
- **Few-shot Learning**: Learns from editor corrections to improve speech text quality over time
- **Title Sync**: Uses the WordPress post title as Babbel story title and keeps it in sync on edits
- **Settings Page**: Configurable prompts, API settings, and story defaults
- **Async Processing**: Uses Action Scheduler for reliable background processing

## Requirements

- WordPress 6.8+
- PHP 8.3 or 8.4
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (ACF)
- OpenAI API access
- A running instance of the [Babbel API](https://github.com/oszuidwest/zwfm-babbel)

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/oszuidwest/zw-knabbel-wp/releases)
2. Upload via WordPress Admin > Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Configure via Settings > ZuidWest Knabbel

## Usage

1. Configure the plugin via Settings > ZuidWest Knabbel
2. When editing a post, check "Radionieuws" in the ACF metabox
3. On publish, the plugin automatically:
   - Uses the post title as the Babbel story title
   - Converts content to speech text via OpenAI
   - Creates a story in the Babbel API
4. Title and date changes are synced to Babbel when you update the post

## Development

### Linting & QA

```bash
# PHP
composer install
vendor/bin/phpcs
vendor/bin/phpstan analyse

# JS/CSS
npm install
npm run lint
npm run lint:fix
```

### Translations

```bash
# Update POT file (requires wp-cli)
wp i18n make-pot . languages/zw-knabbel-wp.pot --slug=zw-knabbel-wp --domain=zw-knabbel-wp

# Compile MO file after editing PO
msgfmt -o languages/zw-knabbel-wp-nl_NL.mo languages/zw-knabbel-wp-nl_NL.po
```

### Release

The GitHub Action reads the version from `zw-knabbel-wp.php` and creates a release on workflow dispatch. The workflow builds `zw-knabbel-wp-{version}.zip` and attaches it to the GitHub Release.

## License

MIT
