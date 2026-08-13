<?php

declare(strict_types=1);

namespace Konventix\Admission\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Models\BadgeTemplate;

/**
 * @extends Factory<BadgeTemplate>
 */
class BadgeTemplateFactory extends Factory
{
    /** @var class-string<BadgeTemplate> */
    protected $model = BadgeTemplate::class;

    /**
     * Defaults to the event fallback (`role: null`) — the row every event
     * needs at minimum.
     *
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'name' => 'Standard badge',
            'role' => null,
            'layout' => new BadgeLayoutData,
        ];
    }

    public function forRole(string $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
            'name' => Str::headline($role).' badge',
        ]);
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $eventId,
        ]);
    }

    public function withLayout(BadgeLayoutData $layout): static
    {
        return $this->state(fn (array $attributes): array => [
            'layout' => $layout,
        ]);
    }
}
