<?php

declare(strict_types=1);

namespace Konventix\Event\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /** @var class-string<Event> */
    protected $model = Event::class;

    /**
     * Note there is no `brand_id` here on purpose: BelongsToBrand stamps it
     * from the resolved brand context, and a test that forgets to establish
     * one should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = fake()->unique()->words(3, true);
        $startsAt = fake()->dateTimeBetween('+1 week', '+2 weeks');
        $endsAt = (clone $startsAt)->modify('+2 hours');

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'timezone' => fake()->timezone(),
            'state' => EventLifecycleState::Draft,
            'reentry_policy' => ReentryPolicy::Unlimited,
            'predecessor_event_id' => null,
            'visibility' => EventVisibility::Public,
            'published_at' => null,
            'cancelled_at' => null,
            'concluded_at' => null,
            'archived_at' => null,
            'reason_code' => null,
        ];
    }

    /**
     * Attach a (primary) venue through the D-EVT-15 pivot.
     */
    public function forVenue(?Venue $venue = null): static
    {
        return $this->afterCreating(function (Event $event) use ($venue): void {
            $venue ??= Venue::factory()->create();

            $event->venues()->attach($venue->id, [
                'is_primary' => true,
                'brand_id' => $event->brand_id,
            ]);
        });
    }

    public function unlisted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => EventVisibility::Unlisted,
        ]);
    }

    public function announced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Announced,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Published,
            'published_at' => now(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Running,
            'published_at' => now(),
        ]);
    }

    public function concluded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Concluded,
            'published_at' => now(),
            'concluded_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Archived,
            'published_at' => now(),
            'concluded_at' => now(),
            'archived_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => EventLifecycleState::Cancelled,
            'reason_code' => 'force-majeure',
            'cancelled_at' => now(),
        ]);
    }
}
