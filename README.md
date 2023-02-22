# silverstripe tx translator

Module to assist with transifex integration. Works with Silverstripe 4.12+ and 5.0+.

## Operations

- Pull latest translations from transifex and merge them into yml/json/js translation files
- Run i18nTextCollectorTask on a local website
- Push updated source strings to transifex (optional)
- Create github pull-requests with file changes

## Requirements

Transifex cli client (tx) - [https://developers.transifex.com/docs/cli](https://developers.transifex.com/docs/cli) configured API key from transifex.

You must use the go version at least version 1.6+ and not the old python version.

## Usage

```
cd /path/to/my-local-site
composer install silverstripe/tx-translator
TX_SITE=my-local-site.test TX_PUSH=1 php vendor/silverstripe/tx-translator/script/translate.php
```

**To omit the push to transifex**

```
TX_SITE=mylocalsite.test php vendor/silverstripe/tx-translator/script/translate.php
```

## Environment variables

Environment variables can either be set via the command line, like in the examples above, or in an .env in the root folder of the site, or using any other methods to set environment variables.

- `TX_SITE` (required) - the url of a local silverstripe site to run i18nTextCollectorTask against. `http://` will be automatically added if protocol is omitted
- `TX_PULL` (default `1`) - pull latest translations from transifex
- `TX_PUSH` (default `0`) - push new source strings to transifex
- `DEV_MODE` (default `0`) - do not push to transifex or create github pull-requests. Useful for local development and for doing dry-runs
