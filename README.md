# ZuidWest Knabbel

WordPress plugin that automatically sends posts to the [Babbel API](https://github.com/oszuidwest/zwfm-babbel) for radio news.

## Features

- **ACF Integration**: Injects a "Radio News" checkbox into a specific ACF field group
- **OpenAI Integration**: Uses GPT models to automatically generate short titles and speech text
- **Settings Page**: Fully configurable prompts and API settings
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
4. Configure via Settings > Knabbel WP

## Usage

1. Configure the plugin via Settings > Knabbel WP
2. When editing a post, check "Radio News" in the metabox
3. On publish, the plugin automatically:
   - Generates a short title via OpenAI
   - Converts content to speech text
   - Creates a story in the Babbel API

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
