# GuardLMS for Laravel

[![Tests](https://github.com/LdesignMedia/laravel-guardlms/actions/workflows/tests.yml/badge.svg)](https://github.com/LdesignMedia/laravel-guardlms/actions/workflows/tests.yml)
[![Packagist version](https://img.shields.io/packagist/v/guardlms/laravel.svg)](https://packagist.org/packages/guardlms/laravel)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Connect your Laravel application's installed software inventory to [GuardLMS](https://guardlms.com).**

GuardLMS for Laravel reports the framework, PHP runtime and Composer packages
actually installed in your application. Run it after deployment or let Laravel's
scheduler keep your website's technology inventory up to date.

[Installation](#installation) · [Connect in GuardLMS](#connect-in-guardlms) · [Scheduling](#scheduling) · [Configuration](#configuration) · [Troubleshooting](#troubleshooting)

## What it reports

| Inventory | Details |
| --- | --- |
| Laravel | The version running in your application |
| PHP | Version, SAPI and loaded extensions |
| Composer packages | Installed package names, exact versions and development dependency flags |
| Server | OS family, architecture and available Linux distribution information |
| Web server | An optional signature you configure, such as `nginx/1.26.3` |
| Available updates | Optional version-matched targets from a Composer outdated report |

Dependency names from `composer.lock` are checked against Composer's installed
runtime metadata. Packages absent from `vendor`, including dev dependencies after
`composer install --no-dev`, are excluded. GuardLMS receives structured inventory;
raw lockfiles, repository credentials and application configuration are not sent.

## Requirements

- PHP **8.2 or later**, Laravel **12**, and Composer **2.2 or later**.
- A deployed `composer.lock` and installed `vendor` directory.
- A GuardLMS website and a **website-bound inventory key** for that website.
- Outbound HTTPS access from the application server to your GuardLMS instance.
- A GuardLMS API with [Laravel inventory support](https://github.com/LdesignMedia/guardlms/pull/2848) deployed.

This release provides inventory reporting. Browser analytics, server error
tracking and browser consent-code onboarding are outside its current scope.

## Installation

Run this in your Laravel application directory:

```bash
composer require guardlms/laravel
```

Laravel discovers the service provider and `guardlms:push` command automatically.
Install the package as a production dependency so it remains available when you
deploy with `composer install --no-dev`.

Next, create the connection in GuardLMS and configure the returned inventory key.

## Connect in GuardLMS

### 1. Add your Laravel website

1. Sign in to the [GuardLMS dashboard](https://dashboard.guardlms.com) with an account that can manage the website.
2. Choose **Add Website** and enter the application's canonical HTTPS URL, for example `https://app.example.com`.
3. Select its environment, confirm ownership and save the website. Complete the ownership verification requested by GuardLMS.
4. Open the website and note its numeric ID in the address bar. For `/websites/123`, the website ID is `123`.

Use a separate website and inventory key for each production, staging or
development environment. The registered URL must match the URL Laravel reports,
including its scheme, hostname and any application subdirectory.

### 2. Create a temporary setup key

1. Open **[API Access](https://dashboard.guardlms.com/api-access)** in GuardLMS.
2. Select **Create API Key**.
3. Give it a recognizable name, such as `Laravel inventory setup`.
4. Select the **`websites:write`** permission and a short expiration date.
5. Create the key and copy its value, which is shown once.

This key authorizes the next setup request. The application will use a separate,
website-bound key returned by that request. If API Access is unavailable for your
account, ask your GuardLMS administrator to issue the website-bound inventory key.

### 3. Request the website-bound inventory key

The current Laravel connection flow uses this API request; there is no Laravel
browser consent screen or dedicated dashboard connection button yet.

In a Bash terminal, set your website ID and enter the temporary setup key when
prompted. For a self-hosted instance, replace `GUARDLMS_URL` with its HTTPS base URL.

```bash
GUARDLMS_URL='https://dashboard.guardlms.com'
GUARDLMS_WEBSITE_ID=123

printf 'Temporary GuardLMS API key: '
read -r -s GUARDLMS_SETUP_TOKEN
printf '\n'

curl --fail-with-body --silent --show-error \
  --request POST \
  "${GUARDLMS_URL}/api/websites/${GUARDLMS_WEBSITE_ID}/inventory-key" \
  --header "Authorization: Bearer ${GUARDLMS_SETUP_TOKEN}" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{"platform":"laravel"}'

unset GUARDLMS_SETUP_TOKEN
```

A successful response has this shape; the token and dates below are placeholders:

```json
{
  "message": "Inventory push key created successfully",
  "data": {
    "token": "123|your-website-bound-inventory-key",
    "siteurl": "https://app.example.com",
    "pushpath": "/api/externalpush/laravel",
    "expires_at": "2027-01-01T00:00:00.000000Z"
  }
}
```

Save **`data.token`** and **`data.siteurl`** for the next step, and record the key's
expiry. The new key has only `inventory:write` permission and is bound to this
website. Issuing it replaces any existing inventory keys for that website.
After setup, revoke the temporary setup key in **API Access**.

### 4. Configure Laravel

Add the returned values to the application's `.env` file or deployment secrets:

```dotenv
GUARDLMS_API_KEY="123|your-website-bound-inventory-key"
GUARDLMS_SITE_URL=https://app.example.com
GUARDLMS_ENABLED=true
GUARDLMS_SCHEDULE_AT=03:17
```

Use **`data.token`** as `GUARDLMS_API_KEY` and **`data.siteurl`** as
`GUARDLMS_SITE_URL`. Keep the key out of version control. If you use a self-hosted
instance, also set `GUARDLMS_API_URL` to the same HTTPS base URL used during setup.

Refresh Laravel's configuration cache after changing deployment settings:

```bash
php artisan config:cache
```

If your application does not use cached configuration, use `php artisan config:clear` instead.

### 5. Send and verify the first inventory

Preview the inventory, then send it:

```bash
php artisan guardlms:push --dry-run
php artisan guardlms:push
```

Dry run prints the collected inventory without sending a request or exposing the
API key. A successful push prints:

```text
GuardLMS inventory received.
```

Return to the website in GuardLMS and open **Tech Stack**. Refresh the page to see
the Laravel and PHP versions, server details and installed Composer dependencies.
An optional update report is required to supply package update targets; without
one, update status remains unknown.

## Scheduling

With `GUARDLMS_ENABLED=true`, the package registers a daily push at
`GUARDLMS_SCHEDULE_AT`, using the application's scheduler timezone. The default
time is **03:17**, with overlap protection.

Ensure your server runs Laravel's scheduler once per minute:

```cron
* * * * * cd /path/to/your-app && php artisan schedule:run >> /dev/null 2>&1
```

Confirm the task is registered:

```bash
php artisan schedule:list
```

If Laravel's scheduler is already configured, keep the existing cron entry.
On deployments with multiple application servers, run this scheduler on one
designated server to avoid duplicate pushes.

You can also add an explicit push to your deployment workflow:

```bash
php artisan guardlms:push
```

The manual command works regardless of `GUARDLMS_ENABLED`; that setting controls
automatic scheduling. Collection and outbound requests do not run during normal
web requests.

## Configuration

Environment variables are sufficient for a standard installation. To customize
the configuration file, publish it with:

```bash
php artisan vendor:publish --tag=guardlms-config
```

| Variable | Default | Purpose |
| --- | --- | --- |
| `GUARDLMS_API_KEY` | Unset | The website-bound inventory key returned during setup |
| `GUARDLMS_SITE_URL` | `APP_URL` | The canonical HTTPS URL registered in GuardLMS |
| `GUARDLMS_API_URL` | `https://dashboard.guardlms.com` | GuardLMS HTTPS base URL, without `/api` |
| `GUARDLMS_ENABLED` | `false` | Register the automatic daily push |
| `GUARDLMS_SCHEDULE_AT` | `03:17` | Daily time in `HH:MM` format |
| `GUARDLMS_OUTDATED_FILE` | Unset | Absolute path to an optional Composer outdated JSON report |
| `GUARDLMS_WEBSERVER` | Unset | Known web server signature, for example `nginx/1.26.3` |

The CLI runtime cannot reliably identify a fronting web server. Set
`GUARDLMS_WEBSERVER` only when you know the actual signature.

### Optional: report available updates

Generate a report on the deployment host using the application's installed
dependency tree. Include transitive dependencies by leaving out `--direct`:

```bash
composer --no-plugins --no-scripts outdated --format=json \
  > storage/app/guardlms-outdated.json.tmp && \
  mv storage/app/guardlms-outdated.json.tmp storage/app/guardlms-outdated.json
```

Then configure its absolute path:

```dotenv
GUARDLMS_OUTDATED_FILE=/path/to/your-app/storage/app/guardlms-outdated.json
```

Regenerate the report at least daily, before the inventory push. The temporary
file and rename keep the connector from reading a partially written report.
Keep the report outside the public web directory.

Only update entries matching a deployed package's exact installed version are
sent. If a configured report is missing, invalid or older than 24 hours, the push
fails and the last stored inventory is preserved. Leave the variable unset if
you do not want to report update targets.

The connector does not launch Composer or shell commands itself. Private package
update checks use the deployment's existing Composer credentials; those
credentials are not included in the inventory.

## Key renewal

Before the inventory key expires, repeat the website-bound key request in
[step 3](#3-request-the-website-bound-inventory-key). Update `GUARDLMS_API_KEY`,
refresh the configuration cache and send a test push. Creating the replacement
revokes the previous inventory key immediately.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| `guardlms:push` is unavailable | Install the package as a production dependency and run `php artisan package:discover`. |
| Inventory collection fails | Run `--dry-run`; confirm `composer.lock` is readable, the site URL uses HTTPS and any configured outdated report is valid and less than 24 hours old. |
| The push fails after collection | Check API reachability, TLS trust, key expiry and that `GUARDLMS_API_KEY` contains the website-bound `data.token`. |
| The API rejects the site URL | Copy `data.siteurl` exactly into `GUARDLMS_SITE_URL`, then refresh cached configuration. |
| Setup rejects `platform: laravel`, or the push endpoint returns 404 | Confirm the GuardLMS API has Laravel inventory support deployed. |
| No automatic pushes appear | Set `GUARDLMS_ENABLED=true`, refresh configuration, inspect `schedule:list` and check the server's scheduler cron entry. |
| Update status is unknown | Configure a fresh Composer outdated report; an omitted report leaves status unknown. |

A failed collection or push returns a nonzero exit code. The command deliberately
keeps HTTP exception details and credentials out of its output.

## Data handling and limits

Inventory is sent to `POST /api/externalpush/laravel` over HTTPS with Bearer-token
authentication. Redirect responses are refused. GuardLMS replaces the previous
dependency set, so removed packages disappear, and ignores older snapshots.

The package sends software inventory rather than application records. It does
not send raw lockfiles, repository URLs, source references, environment dumps,
application configuration, hostnames or user data. Up to **2,000 dependencies**
are supported, with package names up to **120 characters**.

## Development

```bash
composer install
composer test
composer validate --strict
```

CI covers Laravel 12 on PHP 8.2 and 8.4. The published `v0.1.0` release has also been
[verified end to end in local Docker](https://github.com/LdesignMedia/guardlms/pull/2848)
with real HTTPS pushes, database persistence, production dependency removal and
scheduler execution.

Report bugs or request features through [GitHub Issues](https://github.com/LdesignMedia/laravel-guardlms/issues).

## License

[MIT](LICENSE). Maintained by [LdesignMedia](https://ldesignmedia.nl).
