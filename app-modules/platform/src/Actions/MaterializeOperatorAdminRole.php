<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator scope's one seeded role, kept current the way brand defaults
 * are (ADR-0045; hotfix 2026-09-13).
 *
 * "Operator Admin" is created by `konventix:install` with every permission
 * row that exists at that moment — and until this action, nothing ever
 * revisited it: `permissions:sync` materialized defaults for brands only
 * (ADR-0045, "the operator scope keeps its single seeded role"). Every
 * capability a later release added was therefore a row nobody held on the
 * admin host, and the console page behind it answered 403 to the operator
 * who installed the instance: the whole seating console (`resources.*`,
 * 0.6.0) on dev, three days after the release, with no editor for the
 * operator scope to grant it from.
 *
 * Same rule as `MaterializeDefaultRoles`: a role the operator has not
 * touched (`customized_at` null) is re-granted the full catalogue on every
 * sync; a touched one is left exactly as they left it. The role predates
 * `default_key`, so an existing untouched row is stamped on first sight
 * rather than duplicated. The team id is the operator's — that is what the
 * admin host resolves to (ADR-0029) — set for the duration and restored.
 */
final class MaterializeOperatorAdminRole
{
    public const string DEFAULT_KEY = 'platform.operator-admin';

    public const string NAME = 'Operator Admin';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return 'created'|'updated'|'skipped'
     */
    public function handle(Operator $operator): string
    {
        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($operator->getKey());

        try {
            return DB::transaction(function () use ($operator): string {
                $role = Role::query()
                    ->where('brand_id', $operator->getKey())
                    ->where(fn ($query) => $query
                        ->where('default_key', self::DEFAULT_KEY)
                        ->orWhere(fn ($named) => $named->whereNull('default_key')->where('name', self::NAME)))
                    ->orderByRaw('default_key is null')
                    ->first();

                if ($role === null) {
                    $role = Role::query()->create([
                        'name' => self::NAME,
                        'guard_name' => 'web',
                        'brand_id' => $operator->getKey(),
                        'default_key' => self::DEFAULT_KEY,
                    ]);
                    $role->syncPermissions($this->everything());

                    return 'created';
                }

                // Origin is stamped whether or not the grants are touched:
                // it says what the row is, not what it holds, and install
                // looks the role up by it.
                if ($role->default_key === null) {
                    $role->forceFill(['default_key' => self::DEFAULT_KEY])->save();
                }

                if ($role->customized_at !== null) {
                    return 'skipped';
                }

                $role->syncPermissions($this->everything());

                return 'updated';
            });
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $this->registrar->forgetCachedPermissions();
        }
    }

    /**
     * @return Collection<int, Permission>
     */
    private function everything(): Collection
    {
        return Permission::query()->where('guard_name', 'web')->get();
    }
}
