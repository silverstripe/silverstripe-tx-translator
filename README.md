# silverstripe tx translator

Module to assist with transifex integration. Works with Silverstripe 4.12+ and 5.0+.

## Operations

- Pull latest translations from transifex and merge them into yml/json/js translation files
- Run i18nTextCollectorTask on a local website (optional)
- Push updated source strings to transifex (optional)
- Create github pull-requests with file changes

## Requirements

Transifex cli client (tx) - [https://developers.transifex.com/docs/cli](https://developers.transifex.com/docs/cli) configured API key from transifex.

You must use the go version at least version 1.6+ and not the old python version.

Create a new classic github api token in [github token settings](https://github.com/settings/tokens) with all checkboxes unticked except for `public_repo`. This is required to create pull-requests.

Delete this token from github once you have completed updating translations.

## Usage

```
cd /path/to/my-local-site
composer require silverstripe/tx-translator
```

To pull latest translations from transifex, typically this will be done on the lowest supported branch:

```
TX_GITHUB_API_TOKEN=mytoken php vendor/silverstripe/tx-translator/scripts/translate.php
```

To run text collector and update transifex with the latest source strings, typically this will be done on the latest next-minor branch:

```
TX_GITHUB_API_TOKEN=mytoken TX_SITE=my-local-site.test TX_COLLECT=1 TX_PULL=0 TX_PUSH=1 php vendor/silverstripe/tx-translator/scripts/translate.php
```


## Environment variables

Environment variables can either be set via the command line, like in the examples above, or in an .env in the root folder of the site, or using any other methods to set environment variables.

Note that the valid values for the boolean variables are 1, true, on, 0, false and off.

- `TX_GITHUB_API_TOKEN` (required) - the github token with write access to create pull-requests
- `TX_PULL` (default `1`) - pull latest translations from transifex, run i18nTextCollectorTask and update translation files
- `TX_PUSH` (default `0`) - push new source strings to transifex
- `TX_COLLECT` (default `0`) - run i18nTextCollectorTask on local site
- `TX_SITE` (required if `TX_COLLECT` is `1`) - the url of a local silverstripe site to run i18nTextCollectorTask against. `http://` will be automatically added if protocol is omitted
- `TX_DEV_MODE` (default `0`) - do not push to transifex or create github pull-requests. Useful for local development and for doing dry-runs
- `TX_VERBOSE_LOGGING` (default `0`) - show verbose logging
