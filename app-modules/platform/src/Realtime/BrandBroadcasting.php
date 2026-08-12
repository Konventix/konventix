<?php

declare(strict_types=1);

namespace Konventix\Platform\Realtime;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Config\Repository;
use Konventix\Platform\Models\Brand;

/**
 * Points outbound broadcasting at the current brand's Reverb application
 * (ADR-0025): an event broadcast while brand A is resolved is signed with
 * brand A's credentials, and can therefore only reach brand A's channels.
 *
 * Called wherever brand context is established — the ResolveBrand middleware
 * on requests and the queue payload restore in workers. Under Octane the
 * config mutation would otherwise outlive the request, which is exactly why
 * every brand-context change must run through here rather than setting config
 * ad hoc.
 */
class BrandBroadcasting
{
    public function __construct(
        private readonly Repository $config,
        private readonly BroadcastManager $broadcastManager,
    ) {}

    /**
     * Sign broadcasts with the brand's Reverb app credentials.
     */
    public function useBrand(Brand $brand): void
    {
        if ($brand->reverb_app_key === null || $brand->reverb_app_secret === null) {
            return;
        }

        $this->apply([
            'key' => $brand->reverb_app_key,
            'secret' => $brand->reverb_app_secret,
            'app_id' => $brand->id,
        ]);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function apply(array $credentials): void
    {
        $connection = (array) $this->config->get('broadcasting.connections.reverb', []);

        if (
            ($connection['key'] ?? null) === $credentials['key']
            && ($connection['app_id'] ?? null) === $credentials['app_id']
        ) {
            return;
        }

        $this->config->set('broadcasting.connections.reverb', [
            ...$connection,
            ...$credentials,
        ]);

        // The manager caches the constructed Pusher client per connection;
        // without the purge, the first brand's credentials would sign every
        // later broadcast on this worker.
        $this->broadcastManager->purge('reverb');
    }
}
