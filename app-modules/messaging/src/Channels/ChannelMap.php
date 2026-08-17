<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Enums\Channel;
use RuntimeException;

/**
 * Resolves which driver delivers on a channel (ADR-0041) — the same seam
 * shape as Commerce's SettlementAdapterRegistry: adding a channel is one
 * `register()` line in the provider, not a rewrite of the job. Registered
 * as a singleton; drivers resolve from the container so they can take
 * their own dependencies.
 */
final class ChannelMap
{
    /** @var array<string, class-string<MessageChannel>> */
    private array $drivers = [];

    /**
     * @param  class-string<MessageChannel>  $driver
     */
    public function register(Channel $channel, string $driver): void
    {
        $this->drivers[$channel->value] = $driver;
    }

    public function supports(Channel $channel): bool
    {
        return isset($this->drivers[$channel->value]);
    }

    public function driverFor(Channel $channel): MessageChannel
    {
        $driver = $this->drivers[$channel->value] ?? null;

        if ($driver === null) {
            throw new RuntimeException(sprintf(
                'No driver is registered for channel [%s]. Register one in MessagingServiceProvider before topics may declare it (ADR-0041).',
                $channel->value,
            ));
        }

        return app($driver);
    }
}
