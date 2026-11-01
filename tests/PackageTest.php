<?php

namespace GuardLms\Laravel\Tests;

use Composer\InstalledVersions;
use GuardLms\Laravel\Client;
use GuardLms\Laravel\GuardLmsServiceProvider;
use GuardLms\Laravel\Inventory;
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

    public function test_schedule_is_opt_in(): void
    {
        $this->assertCount(0, $this->app->make(Schedule::class)->events());
    }
}
