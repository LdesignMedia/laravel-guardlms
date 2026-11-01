<?php

namespace GuardLms\Laravel\Commands;

use GuardLms\Laravel\Client;
use GuardLms\Laravel\Inventory;
use Illuminate\Console\Command;
use Throwable;

class PushCommand extends Command
{
    protected $signature = 'guardlms:push {--dry-run : Print inventory without sending it}';

    protected $description = 'Push installed Laravel and Composer inventory to GuardLMS';

    public function handle(Inventory $inventory, Client $client): int
    {
        try {
            $payload = $inventory->collect();
            if ($this->option('dry-run')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $client->push($payload);
                $this->info('GuardLMS inventory received.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // HTTP exceptions may contain Authorization headers. Do not log the exception.
            $this->error('GuardLMS inventory push failed. Check configuration, connectivity and the outdated report.');

            return self::FAILURE;
        }
    }
}
