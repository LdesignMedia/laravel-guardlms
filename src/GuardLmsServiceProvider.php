<?php

namespace GuardLms\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class GuardLmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/guardlms.php', 'guardlms');
        $this->app->singleton(Inventory::class);
        $this->app->singleton(Client::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/guardlms.php' => config_path('guardlms.php')], 'guardlms-config');
            $this->commands([Commands\PushCommand::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if (config('guardlms.enabled')) {
                    $schedule->command('guardlms:push')->dailyAt(config('guardlms.schedule_at'))
                        ->withoutOverlapping();
                }
            });
        }
    }
}
