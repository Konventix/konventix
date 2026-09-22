<?php

declare(strict_types=1);

namespace Konventix\Agenda\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;

/**
 * @extends Factory<AgendaItem>
 */
class AgendaItemFactory extends Factory
{
    /** @var class-string<AgendaItem> */
    protected $model = AgendaItem::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id` is a plain ULID (ADR-0014) — Event
     * is another module, so this deliberately does not create a row there,
     * and neither `track_id` nor `location_id` is set, because an item with
     * no track and no place is a legal item.
     *
     * The default carries both a start and an end, which is the common
     * shape; a test after an open-ended item overrides one of them. Both
     * null is refused by the `agenda_items_has_a_time` check constraint
     * (D-AGD-04), which is deliberate.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'title' => fake()->sentence(3),
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'state' => ItemState::Draft,
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $eventId,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ItemState::Published,
        ]);
    }

    public function cancelled(?string $reason = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ItemState::Cancelled,
            'cancelled_reason' => $reason,
        ]);
    }
}
