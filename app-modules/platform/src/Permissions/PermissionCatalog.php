<?php

declare(strict_types=1);

namespace Konventix\Platform\Permissions;

use BackedEnum;
use InterNACHI\Modular\Support\ModuleRegistry;

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
}
