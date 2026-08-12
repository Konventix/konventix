<?php

declare(strict_types=1);

namespace Konventix\Platform\Realtime;

use Illuminate\Support\Collection;
use Konventix\Platform\Models\Brand;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

/**
 * One shared Reverb server, one application per brand (ADR-0025).
 *
 * Brands are rows, so the websocket server reads its applications from the
 * database instead of static config: the brand's ULID is the app id, its
 * provisioned key/secret sign subscriptions, and `allowed_origins` is pinned
 * to exactly the hosts the brand answers on. A client holding brand A's key
 * cannot subscribe to brand B's channels — isolation by credential, not by
 * channel naming and not by process.
 *
 * Lookups are per-connection queries on purpose: the reverb role is a
 * long-lived process, and caching brands here would mean a restart to pick up
 * a new brand — the exact deploy coupling ADR-0025 removes.
 */
class BrandApplicationProvider implements ApplicationProvider
{
    /**
     * Get all of the configured applications as Application instances.
     *
     * @return Collection<int, Application>
     */
    public function all(): Collection
    {
        return Brand::query()
            ->whereNotNull('reverb_app_key')
            ->with('domains')
            ->get()
            ->map(fn (Brand $brand): Application => $this->toApplication($brand));
    }

    /**
     * Find an application instance by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application
    {
        return $this->findBy('id', $id);
    }

    /**
     * Find an application instance by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application
    {
        return $this->findBy('reverb_app_key', $key);
    }

    /**
     * @throws InvalidApplication
     */
    private function findBy(string $column, string $value): Application
    {
        $brand = Brand::query()
            ->where($column, $value)
            ->whereNotNull('reverb_app_key')
            ->with('domains')
            ->first();

        if (! $brand instanceof Brand) {
            throw new InvalidApplication;
        }

        return $this->toApplication($brand);
    }

    private function toApplication(Brand $brand): Application
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('reverb.apps.brand_defaults', []);

        return new Application(
            id: $brand->id,
            key: (string) $brand->reverb_app_key,
            secret: (string) $brand->reverb_app_secret,
            pingInterval: (int) ($defaults['ping_interval'] ?? 60),
            activityTimeout: (int) ($defaults['activity_timeout'] ?? 30),
            allowedOrigins: $this->allowedOrigins($brand),
            maxMessageSize: (int) ($defaults['max_message_size'] ?? 10_000),
            maxConnections: isset($defaults['max_connections']) ? (int) $defaults['max_connections'] : null,
            acceptClientEventsFrom: (string) ($defaults['accept_client_events_from'] ?? 'members'),
            rateLimiting: $defaults['rate_limiting'] ?? null,
            options: $defaults['options'] ?? [],
        );
    }

    /**
     * The brand's own hosts, nothing else. Reverb matches these against the
     * host of the browser-sent Origin header. A brand with no domains gets an
     * unmatchable origin list, never a wildcard.
     *
     * @return array<int, string>
     */
    private function allowedOrigins(Brand $brand): array
    {
        return $brand->domains->pluck('host')->all();
    }
}
