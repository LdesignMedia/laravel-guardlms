# GuardLMS for Laravel

First-party Laravel inventory connector, following the GuardLMS Moodle/WordPress
push model. Reports the running Laravel and PHP versions, PHP extensions, OS
family/architecture, and exact installed Composer dependencies. Requires PHP 8.2+
and Laravel 12. This release covers inventory; browser analytics, server
error tracking and the browser consent-code onboarding flow are not included.

## Install

Install from [Packagist](https://packagist.org/packages/guardlms/laravel):

```sh
composer require guardlms/laravel:^0.1
```

Laravel discovers the service provider and Artisan command automatically.

Register the application's canonical HTTPS URL in GuardLMS and create a
**website-bound inventory key** with `inventory:write`. Configure your deployment's
secret/environment settings:

```dotenv
GUARDLMS_API_KEY=your-website-bound-key
GUARDLMS_SITE_URL=https://your-app.example.com
GUARDLMS_ENABLED=true
GUARDLMS_SCHEDULE_AT=03:17
```

`GUARDLMS_SITE_URL` defaults to `APP_URL`; it must match the registered website,
including any subdirectory. Set a separate website/key for each environment.
`GUARDLMS_API_URL` defaults to `https://dashboard.guardlms.com`; it must use HTTPS.
Do not commit the key. Renew it before its GuardLMS expiry and run
`php artisan config:cache` after configuration changes if your app caches config.

```sh
php artisan guardlms:push --dry-run
php artisan guardlms:push
```

Dry run prints inventory without transmitting it. It never includes your API key.
A push returns a failing exit code on a collection, connection or API error. Your
GuardLMS backend must include Laravel ingestion support from guardlms issue #2041.

## Schedule and deploy

Enable Laravel's scheduler with its usual cron entry:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

With `GUARDLMS_ENABLED=true`, the package registers one daily push at the configured
time in the app timezone, with overlap protection. On multiple application
servers, run the scheduler on one designated server. The default is disabled.
`guardlms:push` can also run explicitly after deployment regardless of that flag.
No inventory collection or outbound HTTP runs during ordinary web requests.

## Optional update information

Generate a Composer report separately on the deployment host, using that deployment's
installed dependency tree. Include transitive dependencies (do not use `--direct`):

```sh
composer --no-plugins --no-scripts outdated --format=json > storage/app/guardlms-outdated.json.tmp && mv storage/app/guardlms-outdated.json.tmp storage/app/guardlms-outdated.json
```

Set `GUARDLMS_OUTDATED_FILE` to the report's absolute path and regenerate it at
least daily before the scheduled push. Use an atomic replacement as above. A
configured missing, invalid or older-than-24-hours report causes collection to fail,
so an unavailable update check cannot silently claim packages are current. Reports
must not be publicly served. Only updates matching the deployed package's exact
version are transmitted. An absent report leaves update status unknown.

The connector never launches Composer or shell commands itself. Private package
update checks therefore use your existing deployment credentials without sending
them to GuardLMS. For web server inventory, optionally configure an actual
`GUARDLMS_WEBSERVER` signature such as `nginx/1.26.3`; the CLI runtime cannot discover
the fronting web server and does not guess one.

## Inventory contract

The POST goes to `/api/externalpush/laravel` with the key as a Bearer token. The
payload contains `siteurl`, `generatedtime`, `laravel.version`, optional
`laravel.updates`, `php`, `server` and `dependencies` (Composer name, installed
version, ecosystem and dev flag). `composer.lock` must be deployed. Its package
names are cross-checked against Composer's runtime inventory; absent dev packages
are excluded and installed runtime versions take precedence over stale lock pins.
No raw lockfile, repository URLs, source references, environment dump, application
configuration, hostname or user data is sent. Up to 2,000 dependencies are supported.
GuardLMS uses its existing lockfile advisory matching and displays dependencies
in the website technology inventory. Older snapshots cannot replace newer ones.

## Configuration and development

```sh
php artisan vendor:publish --tag=guardlms-config
composer install
composer test
composer validate --strict
```

CI tests Laravel 12 on PHP 8.2 and 8.4.

MIT licensed. Maintained by LdesignMedia.
