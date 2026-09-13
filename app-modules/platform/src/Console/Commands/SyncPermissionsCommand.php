<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Konventix\Platform\Actions\MaterializeDefaultRoles;
use Konventix\Platform\Actions\MaterializeOperatorAdminRole;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Permissions\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0013: permission rows are derived state. This command walks every
 * module's `src/Enums/Permission.php` capability catalog and upserts one
 * permission row per case. Rows that no enum declares any more are REPORTED as
 * orphans, never deleted — deleting is an operator decision, because role
 * grants hang off those rows. ADR-0045: default roles are materialized per
 * brand in the same run, so a deploy that adds a capability to an enum
 * reaches every brand's untouched roles.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync
        {--guard=web : The auth guard the permissions belong to}
        {--no-roles : Upsert capabilities only; leave every brand\'s default roles alone}';

    protected $description = 'Upsert permission rows from every module\'s Permission enum, report orphans, and materialize default roles per brand';

    public function handle(PermissionCatalog $catalog, PermissionRegistrar $registrar, MaterializeDefaultRoles $materialize, MaterializeOperatorAdminRole $materializeOperatorAdmin): int
    {
        $guard = (string) $this->option('guard');

        $declared = $catalog->all();

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

        if ($this->option('no-roles')) {
            return self::SUCCESS;
        }

        // The operator scope's own seeded role, first (hotfix 2026-09-13):
        // a capability a release adds has to reach the admin host too, or
        // the console page behind it is a 403 for the operator who
        // installed the instance. Same untouched-only rule as the brands.
        foreach (Operator::query()->orderBy('name')->get() as $operator) {
            $this->line(sprintf('Operator Admin for [%s]: %s', $operator->name, $materializeOperatorAdmin->handle($operator)));
        }

        // ADR-0045: defaults are materialized here so a deploy that adds a
        // capability to an enum reaches every brand's untouched roles.
        foreach (Brand::query()->orderBy('name')->get() as $brand) {
            $report = $materialize->handle($brand);

            $this->line(sprintf('Default roles for [%s]: %s', $brand->name, $report->summary()));

            foreach ($report->skippedCollision as $name) {
                $this->warn("  Skipped [{$name}] on [{$brand->name}] — an operator-made role already carries that name.");
            }
        }

        return self::SUCCESS;
    }
}
