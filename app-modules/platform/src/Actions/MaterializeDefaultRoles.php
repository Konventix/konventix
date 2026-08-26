<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Permissions\MaterializationReport;
use Konventix\Platform\Permissions\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns every active module's default roles into rows for one brand
 * (ADR-0045). Three outcomes per default: absent → created; present and
 * untouched → name and grants re-synced, so a capability added to an enum
 * reaches every brand that never edited the role; present and customized →
 * left exactly as the operator left it. A name collision with an
 * operator-made role is reported and skipped, never hijacked.
 *
 * Runs with the brand in context (the gate needs it to answer "active") and
 * spatie's team id set to the brand, both restored afterwards — a console
 * command materializes many brands in one process.
 */
final class MaterializeDefaultRoles
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly ModuleGate $gate,
        private readonly BrandContext $context,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Brand $brand, ?string $module = null): MaterializationReport
    {
        $report = new MaterializationReport;
        $previousTeam = $this->registrar->getPermissionsTeamId();

        $this->registrar->setPermissionsTeamId($brand->getKey());

        try {
            $this->context->withBrand($brand, function () use ($brand, $module, $report): void {
                DB::transaction(function () use ($brand, $module, $report): void {
                    foreach ($this->catalog->defaultRoles() as $owner => $roles) {
                        if (($module !== null && $owner !== $module) || ! $this->gate->isActive($owner)) {
                            continue;
                        }

                        foreach ($roles as $role) {
                            $this->materialize($brand, $role, $report);
                        }
                    }
                });
            });
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $this->registrar->forgetCachedPermissions();
        }

        return $report;
    }

    private function materialize(Brand $brand, DefaultRole $default, MaterializationReport $report): void
    {
        $existing = Role::query()
            ->where('brand_id', $brand->getKey())
            ->where('default_key', $default->key)
            ->first();

        if ($existing === null) {
            if ($this->nameTaken($brand, $default->name, null)) {
                $report->skippedCollision[] = $default->name;

                return;
            }

            $role = Role::query()->create([
                'name' => $default->name,
                'guard_name' => 'web',
                'brand_id' => $brand->getKey(),
                'default_key' => $default->key,
            ]);
            $role->syncPermissions($this->rowsFor($default));

            $report->created[] = $default->name;

            return;
        }

        if ($existing->customized_at !== null) {
            $report->skippedCustomized[] = $existing->name;

            return;
        }

        if ($existing->name !== $default->name) {
            if ($this->nameTaken($brand, $default->name, $existing->getKey())) {
                $report->skippedCollision[] = $default->name;

                return;
            }

            $existing->forceFill(['name' => $default->name])->save();
        }

        $existing->syncPermissions($this->rowsFor($default));

        $report->updated[] = $default->name;
    }

    private function nameTaken(Brand $brand, string $name, ?string $ignoreRoleId): bool
    {
        return Role::query()
            ->where('brand_id', $brand->getKey())
            ->where('name', $name)
            ->when($ignoreRoleId !== null, fn ($query) => $query->whereKeyNot($ignoreRoleId))
            ->exists();
    }

    /**
     * Permission rows exist before any grant, exactly as `permissions:sync`
     * creates them (ADR-0013: derived state, never hand-written).
     *
     * @return list<Permission>
     */
    private function rowsFor(DefaultRole $default): array
    {
        $names = $default->everything ? $this->catalog->all() : $default->permissions;

        return array_map(
            fn (string $name): Permission => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']),
            $names,
        );
    }
}
