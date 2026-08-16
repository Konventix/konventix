<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Startup entrypoint for the migrator role (compose one-shot service today,
 * a Kubernetes-Job-style container in any future deployment). Ships with the
 * application image, so every deployment target gets the same gate: run
 * pending migrations unless the operator disabled it via AUTO_MIGRATE=false.
 */
class MigrateOnStartCommand extends Command
{
    protected $signature = 'konventix:migrate';

    protected $description = 'Run pending migrations unless AUTO_MIGRATE=false';

    public function handle(): int
    {
        if (! config()->boolean('database.auto_migrate')) {
            $this->info('AUTO_MIGRATE=false — skipping automatic migration.');

            return self::SUCCESS;
        }

        return $this->call('migrate', ['--force' => true]);
    }
}
