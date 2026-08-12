<?php

declare(strict_types=1);

namespace Konventix\Event\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;

/**
 * @extends Factory<EventDay>
 */
class EventDayFactory extends Factory
{
    /** @var class-string<EventDay> */
    protected $model = EventDay::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. Prefer `Actions\SyncEventDays` in feature
     * tests — this factory exists for read-side tests that need a day row
     * without exercising the sync semantics.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'date' => fake()->unique()->dateTimeBetween('+1 week', '+8 weeks')->format('Y-m-d'),
            'sequence' => 1,
            'label' => null,
        ];
    }

    public function forEvent(Event $event, int $sequence = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event->id,
            'sequence' => $sequence,
        ]);
    }
}
