<?php

declare(strict_types=1);

namespace Konventix\Messaging\Topics;

use InvalidArgumentException;
use Konventix\Messaging\Enums\TopicAudience;

/**
 * Boot-time topic registrations (ADR-0041), a singleton exactly like
 * `TemplateRegistry`: modules declare their topics from their own
 * ServiceProvider, the preference page renders from this registry, and
 * `SendMessage` resolves delivery policy from it. Safe to share across
 * Octane requests — registrations happen at boot and never mutate after.
 */
final class TopicRegistry
{
    /** @var array<string, Topic> */
    private array $topics = [];

    public function register(Topic $topic): void
    {
        $this->topics[$topic->key] = $topic;
    }

    public function has(string $key): bool
    {
        return isset($this->topics[$key]);
    }

    public function get(string $key): Topic
    {
        return $this->topics[$key] ?? throw new InvalidArgumentException(sprintf(
            'No topic [%s] is registered. Declare it in the owning module\'s ServiceProvider before sending on it (ADR-0041).',
            $key,
        ));
    }

    /**
     * @return array<string, Topic>
     */
    public function all(): array
    {
        return $this->topics;
    }

    /**
     * The keys declared for one audience — what the inbox filters rows by
     * and the preferences page hides from accounts outside that audience.
     *
     * @return list<string>
     */
    public function keysFor(TopicAudience $audience): array
    {
        return array_keys(array_filter(
            $this->topics,
            fn (Topic $topic): bool => $topic->audience === $audience,
        ));
    }
}
