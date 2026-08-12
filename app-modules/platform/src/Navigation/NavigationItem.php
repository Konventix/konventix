<?php

declare(strict_types=1);

namespace Konventix\Platform\Navigation;

/**
 * One navigation entry contributed by a module.
 *
 * LanCore's sidebar was a 1,285-line component that knew every domain. Here a
 * module hands Platform a value object and knows nothing about the shell.
 */
class NavigationItem
{
    public const AREA_BRAND = 'brand';

    public const AREA_ADMIN = 'admin';

    /**
     * @param  string  $module  the owning module, used to gate visibility
     * @param  string  $label  translation key or literal label
     * @param  string  $url  resolved URL; build it with Wayfinder helpers
     * @param  string|null  $icon  icon identifier the shell understands
     * @param  int  $order  lower sorts first
     * @param  string|null  $permission  capability required to see the entry
     * @param  string  $area  which shell shows it: brand app or operator admin (ADR-0029)
     */
    public function __construct(
        public readonly string $module,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $icon = null,
        public readonly int $order = 100,
        public readonly ?string $permission = null,
        public readonly string $area = self::AREA_BRAND,
    ) {}

    /**
     * @return array{module: string, label: string, url: string, icon: string|null, order: int}
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'order' => $this->order,
        ];
    }
}
