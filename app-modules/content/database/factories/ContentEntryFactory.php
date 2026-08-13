<?php

declare(strict_types=1);

namespace Konventix\Content\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\ContentEntry;

/**
 * @extends Factory<ContentEntry>
 */
class ContentEntryFactory extends Factory
{
    /** @var class-string<ContentEntry> */
    protected $model = ContentEntry::class;

    /**
     * Draft by default: publishing is an act with events attached, so a
     * factory that published implicitly would make every test emit them.
     *
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'event_id' => null,
            'author_user_id' => (string) Str::ulid(),
            'type' => ContentType::News,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'title' => $title,
            'teaser' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'state' => PublicationState::Draft,
            'audience' => Audience::Everyone,
            'urgency' => null,
            'published_at' => null,
            'comments_enabled' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => PublicationState::Published,
            'published_at' => now()->subHour(),
        ]);
    }

    public function announcement(Urgency $urgency = Urgency::Normal): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ContentType::Announcement,
            'urgency' => $urgency,
        ]);
    }

    public function withComments(): static
    {
        return $this->state(fn (array $attributes): array => ['comments_enabled' => true]);
    }

    public function loggedInOnly(): static
    {
        return $this->state(fn (array $attributes): array => ['audience' => Audience::LoggedIn]);
    }
}
