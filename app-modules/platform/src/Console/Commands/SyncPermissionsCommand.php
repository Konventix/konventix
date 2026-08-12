<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\ModuleRegistry;
use Konventix\Platform\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0013: permission rows are derived state. This command walks every
 * module's `src/Enums/Permission.php` capability catalog and upserts one
 * permission row per case. Rows that no enum declares any more are REPORTED as
 * orphans, never deleted — deleting is an operator decision, because role
 * grants hang off those rows.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync {--guard=web : The auth guard the permissions belong to}';

    protected $description = 'Upsert permission rows from every module\'s Permission enum and report orphans';

    public function handle(ModuleRegistry $modules, PermissionRegistrar $registrar): int
    {
        $guard = (string) $this->option('guard');

        $declared = $this->declaredPermissions($modules);

        if ($declared === []) {
            $this->warn('No module declares a Permission enum yet.');

            return self::SUCCESS;
        }

        $created = 0;

        foreach ($declared as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);

            $created += (int) $permission->wasRecentlyCreated;
        }

        $orphans = Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', $declared)
            ->pluck('name');

        $registrar->forgetCachedPermissions();

        $this->info(sprintf(
            'Synced %d capabilities (%d new) from %d module catalogs.',
            count($declared),
            $created,
            count(array_unique(array_map(fn (string $name): string => Str::before($name, '.'), $declared))),
        ));

        foreach ($orphans as $orphan) {
            $this->warn("Orphaned permission row [{$orphan}] — no enum declares it. Review before removing.");
        }

        return self::SUCCESS;
    }

    /**
     * Every case value of every module's Permission enum, deduplicated.
     *
     * @return array<int, string>
     */
    private function declaredPermissions(ModuleRegistry $modules): array
    {
        $names = [];

        foreach ($modules->modules() as $module) {
            /** @var class-string $enum */
            $enum = $module->qualify('Enums\\Permission');

            if (! enum_exists($enum)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                if ($case instanceof BackedEnum) {
                    $names[] = (string) $case->value;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
