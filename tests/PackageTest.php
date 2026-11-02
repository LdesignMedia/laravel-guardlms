<?php

namespace GuardLms\Laravel\Tests;

use Composer\InstalledVersions;
use GuardLms\Laravel\Client;
use GuardLms\Laravel\GuardLmsServiceProvider;
use GuardLms\Laravel\Inventory;
use GuardLms\Laravel\JsonFileReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class PackageTest extends TestCase
{
    private array $runtime;

    private string $directory;

    protected function getPackageProviders($app): array
    {
        return [GuardLmsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = InstalledVersions::getAllRawData();
        $this->directory = sys_get_temp_dir().'/guardlms-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        config(['guardlms.site_url' => 'https://laravel.example.com', 'guardlms.api_key' => 'secret-test-key']);
    }

    protected function tearDown(): void
    {
        InstalledVersions::reload($this->runtime);
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function inventory(): Inventory
    {
        file_put_contents($this->directory.'/composer.lock', json_encode([
            'packages' => [['name' => 'vendor/runtime', 'version' => '1.0.0', 'source' => ['url' => 'secret-source']]],
            'packages-dev' => [['name' => 'vendor/dev', 'version' => '1.0.0']],
        ]));
        InstalledVersions::reload(['root' => ['name' => 'test/app', 'dev' => false], 'versions' => [
            'vendor/runtime' => ['pretty_version' => '1.1.0', 'version' => '1.1.0.0', 'dev_requirement' => false],
        ]]);
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('basePath')->with('composer.lock')->andReturn($this->directory.'/composer.lock');
        $app->shouldReceive('version')->andReturn('12.0.0');

        return new Inventory($app);
    }

    public function test_reports_runtime_versions_and_excludes_absent_dev_packages_and_lock_secrets(): void
    {
        $payload = $this->inventory()->collect();
        $this->assertSame([['ecosystem' => 'composer', 'name' => 'vendor/runtime', 'version' => '1.1.0', 'dev' => false]], $payload['dependencies']);
        $this->assertSame('12.0.0', $payload['laravel']['version']);
        $this->assertStringNotContainsString('secret-source', json_encode($payload));
        $this->assertArrayNotHasKey('updates', $payload['laravel']);
    }

    public function test_installed_dev_dependencies_are_reported_as_dev(): void
    {
        $inventory = $this->inventory();
        InstalledVersions::reload(['root' => ['name' => 'test/app', 'dev' => true], 'versions' => [
            'vendor/dev' => ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'dev_requirement' => true],
        ]]);
        $this->assertSame([['ecosystem' => 'composer', 'name' => 'vendor/dev', 'version' => '1.0.0', 'dev' => true]], $inventory->collect()['dependencies']);
    }

    public function test_enabled_schedule_registers_one_daily_push(): void
    {
        config(['guardlms.enabled' => true, 'guardlms.schedule_at' => '04:20']);
        $events = $this->app->make(Schedule::class)->events();
        $this->assertCount(1, $events);
        $this->assertSame('20 4 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_only_reports_updates_matching_installed_versions(): void
    {
        $inventory = $this->inventory();
        $path = $this->directory.'/outdated.json';
        file_put_contents($path, json_encode(['installed' => [
            ['name' => 'vendor/runtime', 'version' => '1.1.0', 'latest' => '1.2.0'],
            ['name' => 'vendor/dev', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ]]));
        config(['guardlms.outdated_file' => $path]);
        $this->assertSame([['name' => 'vendor/runtime', 'version' => '1.1.0', 'latest' => '1.2.0']], $inventory->collect()['laravel']['updates']);
        touch($path, time() - 90000);
        clearstatcache();
        $this->expectException(RuntimeException::class);
        $inventory->collect();
    }

    public function test_push_uses_website_key_and_never_follows_redirects(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response(['message' => 'Inventory received.'], 200)]);
        $this->app->make(Client::class)->push(['siteurl' => 'https://laravel.example.com']);
        $http->assertSent(fn ($request) => $request->url() === 'https://dashboard.guardlms.com/api/externalpush/laravel'
            && $request->hasHeader('Authorization', 'Bearer secret-test-key'));
        $http->assertSentCount(1);
    }

    public function test_rejects_http_endpoint_before_transmitting_a_key(): void
    {
        config(['guardlms.api_url' => 'http://example.com']);
        $http = $this->app->make(Factory::class);
        $http->fake();
        try {
            $this->app->make(Client::class)->push([]);
            $this->fail('Expected insecure endpoint rejection');
        } catch (RuntimeException $e) {
            $http->assertNothingSent();
        }
    }

    public function test_redirect_response_is_failure_and_body_is_not_exposed(): void
    {
        $http = $this->app->make(Factory::class);
        $http->fake(['*' => $http->response('private-response', 302)]);
        try {
            $this->app->make(Client::class)->push([]);
            $this->fail('Expected redirect rejection');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('private-response', $e->getMessage());
        }
        $http->assertSentCount(1);
    }

    public function test_tls_and_redirect_policy_override_insecure_host_defaults(): void
    {
        $http = $this->app->make(Factory::class);
        $http->globalOptions(['verify' => false, 'allow_redirects' => true]);
        $options = [];
        $http->fake(function ($request, $requestOptions) use ($http, &$options) {
            $options = $requestOptions;

            return $http->response([], 200);
        });
        $this->app->make(Client::class)->push([]);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
    }

    public function test_custom_ca_bundle_is_preserved(): void
    {
        $http = $this->app->make(Factory::class);
        $http->globalOptions(['verify' => '/trusted/custom-ca.pem']);
        $options = [];
        $http->fake(function ($request, $requestOptions) use ($http, &$options) {
            $options = $requestOptions;

            return $http->response([], 200);
        });
        $this->app->make(Client::class)->push([]);
        $this->assertSame('/trusted/custom-ca.pem', $options['verify']);
    }

    public function test_site_url_rejects_credentials_query_and_fragment(): void
    {
        $inventory = $this->inventory();
        foreach (['https://user:password@example.com', 'https://example.com?secret=value', 'https://example.com#secret'] as $url) {
            config(['guardlms.site_url' => $url]);
            try {
                $inventory->collect();
                $this->fail('Expected site URL rejection');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('password', $e->getMessage());
                $this->assertStringNotContainsString('secret', $e->getMessage());
            }
        }
    }

    public function test_json_reader_rejects_oversized_files_before_decoding(): void
    {
        $path = $this->directory.'/large.json';
        file_put_contents($path, str_repeat('x', 65));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size limit');
        (new JsonFileReader)->read($path, 64);
    }

    public function test_json_reader_rejects_stream_wrappers_and_non_files(): void
    {
        foreach (['php://memory', 'data://text/plain,{}', 'file:///etc/passwd', $this->directory] as $path) {
            try {
                (new JsonFileReader)->read($path, 1024);
                $this->fail('Expected local regular file requirement');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_json_reader_limits_nesting_depth(): void
    {
        $path = $this->directory.'/deep.json';
        file_put_contents($path, str_repeat('{"nested":', 65).'null'.str_repeat('}', 65));
        $this->expectException(\JsonException::class);
        (new JsonFileReader)->read($path, 4096);
    }

    public function test_updates_are_deduplicated_and_unsafe_targets_are_omitted(): void
    {
        $inventory = $this->inventory();
        $path = $this->directory.'/outdated.json';
        $rows = [];
        foreach (['', str_repeat('v', 101), "1.2.0\n", '1.2.0', '1.3.0'] as $latest) {
            $rows[] = ['name' => 'vendor/runtime', 'version' => '1.1.0', 'latest' => $latest];
        }
        file_put_contents($path, json_encode(['installed' => $rows]));
        config(['guardlms.outdated_file' => $path]);
        $this->assertSame([['name' => 'vendor/runtime', 'version' => '1.1.0', 'latest' => '1.2.0']], $inventory->collect()['laravel']['updates']);
    }

    public function test_oversized_report_entry_count_is_rejected(): void
    {
        $inventory = $this->inventory();
        $path = $this->directory.'/outdated.json';
        file_put_contents($path, json_encode(['installed' => array_fill(0, 2001, ['name' => 'vendor/runtime', 'version' => '1.1.0', 'latest' => '1.2.0'])]));
        config(['guardlms.outdated_file' => $path]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2000 entries');
        $inventory->collect();
    }

    public function test_malformed_lockfile_entries_fail_collection(): void
    {
        $inventory = $this->inventory();
        file_put_contents($this->directory.'/composer.lock', json_encode(['packages' => [['name' => ['invalid']]]]));
        $this->expectException(RuntimeException::class);
        $inventory->collect();
    }

    public function test_cli_failure_does_not_expose_http_exception_details(): void
    {
        $this->app->instance(Inventory::class, Mockery::mock(Inventory::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andReturn([]);
        }));
        $http = $this->app->make(Factory::class);
        $http->fake(fn () => throw new RuntimeException('Authorization: Bearer secret-test-key'));
        $this->artisan('guardlms:push')
            ->expectsOutput('GuardLMS inventory push failed. Check configuration, connectivity and the outdated report.')
            ->doesntExpectOutputToContain('secret-test-key')
            ->assertExitCode(1);
    }

    public function test_schedule_is_opt_in(): void
    {
        $this->assertCount(0, $this->app->make(Schedule::class)->events());
    }
}
