<?php

declare(strict_types=1);

namespace Konventix\Messaging\Topics;

use InvalidArgumentException;
use Konventix\Messaging\Enums\Channel;

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
