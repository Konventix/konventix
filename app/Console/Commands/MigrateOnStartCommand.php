<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Startup entrypoint for the migrator role (compose one-shot service today,
 * a Kubernetes-Job-style container in any future deployment). Ships with the
 * application image, so every deployment target gets the same gate: run
 * pending migrations and sync permissions unless the operator disabled it
 * via AUTO_MIGRATE=false.
 *
 * Everything this runs after the migration is derived state that must be
 * true on every release and is safe to assert twice: permission rows and
 * default roles (ADR-0013, ADR-0045), and every brand's entrance signing
 * key (ADR-0033, #287). A new deploy step was deliberately not added for
 * the latter — it belongs behind the same gate, in the same Job, as the
 * thing it is a sibling of.
 */
class MigrateOnStartCommand extends Command
{
    protected $signature = 'konventix:migrate';

    protected $description = 'Run pending migrations, sync permissions and mint missing entrance signing keys unless AUTO_MIGRATE=false';

    public function handle(): int
    {
        if (! config()->boolean('database.auto_migrate')) {
            $this->info('AUTO_MIGRATE=false — skipping automatic migration.');

            return self::SUCCESS;
        }

        $migrated = $this->call('migrate', ['--force' => true]);

        if ($migrated !== self::SUCCESS) {
            return $migrated;
        }

        // Platform's TODO said "must run in every deploy" and nothing
        // automated it — now the migrator role does, behind the same gate
        // (ADR-0049 §3). Roles the operator has edited are never overwritten
        // (ADR-0045); syncing twice is a no-op.
        $synced = $this->call('permissions:sync');

        if ($synced !== self::SUCCESS) {
            return $synced;
        }

        // #287: a brand with no active entrance signing key answers 500 on
        // every ticket download. Brands created through the console get one
        // from `BrandCreated`; brands that predate that listener, and brands
        // built by `konventix:install` or a seeder, get one here. `--all`
        // only fills gaps — it never rotates a key that still signs — so it
        // is safe on every release.
        return $this->call('admission:rotate-signing-key', ['--all' => true]);
    }
}
