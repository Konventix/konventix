<?php

declare(strict_types=1);

namespace Konventix\Event\Targets;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Event\Models\Event;
use Konventix\Platform\Data\TargetOptionData;
use Konventix\Platform\Targets\TargetProvider;

/**
 * Event's half of Platform's target registry (ADR-0066 §7.3, D-CPL-11):
 * the `event` kind, named for whatever console picker asks.
 *
 * This module learns nothing about why it is asked. The registry's kinds are
 * generic — "name these ids for a picker" — and the only caller today is
 * Compliance's binding editor, which may not read this table itself
 * (ADR-0004).
 *
 * `withoutBrandScope()` throughout: the console asks from the operator admin
 * host, where `BrandScope` hardens every brand-owned query to zero rows
 * (ADR-0029), and the brand filter is then restated by hand.
 */
final class EventTargetProvider implements TargetProvider
{
    public const string KIND = 'event';

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return [self::KIND];
    }

    /**
     * @return list<TargetOptionData>
     */
    public function optionsFor(?string $brandId): array
    {
        return array_values(Event::withoutBrandScope()
            ->whereNull('archived_at')
            ->when($brandId !== null, fn (Builder $query): Builder => $query->where('brand_id', $brandId))
            ->orderByDesc('starts_at')
            ->get(['id', 'name'])
            ->map(fn (Event $event): TargetOptionData => new TargetOptionData(
                kind: self::KIND,
                id: (string) $event->getKey(),
                label: $event->name,
                group: __('Events'),
            ))
            ->all());
    }

    public function labelFor(string $kind, string $id): ?string
    {
        if ($kind !== self::KIND) {
            return null;
        }

        /** @var string|null $name */
        $name = Event::withoutBrandScope()->whereKey($id)->value('name');

        return $name;
    }
}
