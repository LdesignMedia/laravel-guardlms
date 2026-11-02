<?php

namespace GuardLms\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

class Inventory
{
    public const MAX_LOCK_BYTES = 16 * 1024 * 1024;

    public const MAX_REPORT_BYTES = 2 * 1024 * 1024;

    public function __construct(private Application $app, private JsonFileReader $files = new JsonFileReader) {}

    private function osRelease(): array
    {
        if (! is_readable('/etc/os-release')) {
            return [];
        }
        $values = parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW);
        if (! is_array($values)) {
            return [];
        }
        $result = [];
        foreach (['NAME' => 'os_name', 'ID' => 'os_id', 'VERSION_ID' => 'os_version', 'PRETTY_NAME' => 'os_pretty'] as $key => $field) {
            if (is_string($values[$key] ?? null)) {
                $result[$field] = trim($values[$key], '\"');
            }
        }

        return $result;
    }

    public function collect(): array
    {
        $site = rtrim((string) config('guardlms.site_url'), '/');
        if (! filter_var($site, FILTER_VALIDATE_URL) || parse_url($site, PHP_URL_SCHEME) !== 'https'
            || parse_url($site, PHP_URL_USER) !== null || parse_url($site, PHP_URL_QUERY) !== null
            || parse_url($site, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('GUARDLMS_SITE_URL must be the registered HTTPS website URL.');
        }
        $lockPath = $this->app->basePath('composer.lock');
        $lock = $this->files->read($lockPath, self::MAX_LOCK_BYTES);
        if (! is_array($lock['packages'] ?? null)) {
            throw new RuntimeException('Invalid composer.lock package list.');
        }
        $dependencies = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            if (isset($lock[$section]) && ! is_array($lock[$section])) {
                throw new RuntimeException('Invalid composer.lock package list.');
            }
            foreach ($lock[$section] ?? [] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                    throw new RuntimeException('Invalid composer.lock package entry.');
                }
                $name = $package['name'];
                // Lockfiles can include dev dependencies absent from the deployed vendor tree.
                if (! InstalledVersions::isInstalled($name)) {
                    continue;
                }
                if (strlen($name) > 120) {
                    throw new RuntimeException('A dependency name exceeds the GuardLMS limit of 120 characters.');
                }
                $version = InstalledVersions::getPrettyVersion($name);
                if ($version === null) {
                    continue;
                }
                $dependencies[] = ['ecosystem' => 'composer', 'name' => $name, 'version' => $version, 'dev' => $dev];
                if (count($dependencies) > 2000) {
                    throw new RuntimeException('Inventory exceeds the GuardLMS limit of 2000 dependencies.');
                }
            }
        }
        $payload = [
            'siteurl' => $site,
            'generatedtime' => time(),
            'laravel' => ['version' => $this->app->version()],
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => get_loaded_extensions()],
            'server' => array_filter(['os_family' => PHP_OS_FAMILY, 'arch' => php_uname('m'),
                'webserver' => config('guardlms.webserver')], fn ($v) => $v !== null) + $this->osRelease(),
            'dependencies' => $dependencies,
        ];
        $path = config('guardlms.outdated_file');
        if ($path) {
            // A missing/invalid configured report fails the push, preserving last good data.
            $report = $this->files->read($path, self::MAX_REPORT_BYTES, time() - 86400);
            if (! is_array($report['installed'] ?? null)) {
                throw new RuntimeException('Invalid Composer outdated JSON report.');
            }
            if (count($report['installed']) > 2000) {
                throw new RuntimeException('Composer outdated report exceeds 2000 entries.');
            }
            $installed = array_column($dependencies, 'version', 'name');
            $updates = [];
            foreach ($report['installed'] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                    throw new RuntimeException('Invalid Composer outdated report entry.');
                }
                $name = $package['name'];
                if (isset($installed[$name]) && ($package['version'] ?? null) === $installed[$name]
                    && is_string($package['latest'] ?? null)
                    && trim($package['latest']) !== '' && strlen($package['latest']) <= 100
                    && ! preg_match('/[\x00-\x1f\x7f]/', $package['latest']) && ! isset($updates[$name])) {
                    $updates[$name] = ['name' => $name, 'version' => $installed[$name], 'latest' => $package['latest']];
                }
            }
            $payload['laravel']['updates'] = array_values($updates);
        }

        return $payload;
    }
}
