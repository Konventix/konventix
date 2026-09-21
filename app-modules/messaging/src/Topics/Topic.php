<?php

declare(strict_types=1);

namespace Konventix\Messaging\Topics;

use InvalidArgumentException;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\TopicAudience;

/**
 * A topic is the unit of preference and delivery policy (ADR-0041,
 * D-MSG-05): modules declare one per kind of notification at boot, next to
 * the templates that use it. A new notification kind is one declaration —
 * no migration, no settings-page edit; LanCore's sixteen-boolean preference
 * matrix is the counterexample.
 *
 * `suppressible: false` marks security-relevant topics (token rotated,
 * password changed): preferences cannot disable them — the mail
 * suppression list still applies, because a hard-bounced address is a
 * deliverability fact, not a choice. `digestable: true` lets the digest
 * window coalesce the topic's sends; urgent sends bypass it (D-CNT-08).
 *
 * `audience` says which inbox the topic's in-app rows belong to (bugfix
 * round 2026-09-21, Task 9): participant topics show on the brand host,
 * operator topics on the operator's admin host, and the preferences page
 * offers an operator topic only to accounts that can reach the console.
 * Defaults to participant, so a declaration that never thought about the
 * console is a participant notice — the safe reading.
 */
final readonly class Topic
{
    /**
     * @param  list<Channel>  $defaultChannels
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $defaultChannels,
        public bool $suppressible = true,
        public bool $digestable = false,
        public TopicAudience $audience = TopicAudience::Participant,
    ) {
        if ($defaultChannels === []) {
            throw new InvalidArgumentException(sprintf(
                'Topic [%s] declares no default channels; a topic no channel carries can never reach anyone.',
                $key,
            ));
        }
    }

    public function allowsChannel(Channel $channel): bool
    {
        return in_array($channel, $this->defaultChannels, true);
    }
}
