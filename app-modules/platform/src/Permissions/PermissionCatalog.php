<?php

declare(strict_types=1);

namespace Konventix\Platform\Permissions;

use BackedEnum;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\ModuleRegistry;
use InvalidArgumentException;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * The platform's capability catalogue (ADR-0013): every case of every
 * module's `src/Enums/Permission.php`, discovered — never a central list
 * to keep in sync. `permissions:sync` upserts rows from it; the role
 * editor renders and validates grants against it.
 */
class PermissionCatalog
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Every declared capability name, deduplicated.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $names = [];

        foreach ($this->modules->modules() as $module) {
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

    /**
     * The catalogue grouped by its module prefix (`platform.view-brands`
     * → group `platform`), each group's names sorted — the shape the role
     * editor renders.
     *
     * @return array<string, list<string>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->all() as $name) {
            $grouped[explode('.', $name, 2)[0]][] = $name;
        }

        ksort($grouped);

        foreach ($grouped as &$names) {
            sort($names);
        }

        return $grouped;
    }

    /**
     * Every module's default roles, keyed by module (ADR-0045). Discovered
     * the same way the enum is — by resolving the module's provider class
     * from the registry — so a console command needs no boot ordering.
     *
     * @return array<string, list<DefaultRole>>
     */
    public function defaultRoles(): array
    {
        $sets = [];

        foreach ($this->modules->modules() as $module) {
            $provider = $module->qualify('Providers\\'.Str::studly($module->name).'ServiceProvider');

            if (! class_exists($provider) || ! is_subclass_of($provider, ModuleServiceProvider::class)) {
                continue;
            }

            /** @var list<DefaultRole> $roles */
            $roles = (new $provider(app()))->defaultRoles();

            foreach ($roles as $role) {
                if ($role->module() !== $module->name) {
                    throw new InvalidArgumentException(
                        "Module [{$module->name}] declares default role [{$role->key}] under another module's prefix."
                    );
                }
            }

            if ($roles !== []) {
                $sets[$module->name] = $roles;
            }
        }

        return $sets;
    }
}
