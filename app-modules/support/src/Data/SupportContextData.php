<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The technical context stored with a report (design §5) — the one thing
 * the reporter cannot be expected to know and the whole reason the launcher
 * beats an e-mail address.
 *
 * **Two correlation ids, because two requests are involved.**
 * `pageCorrelationId` belongs to the page that misbehaved and is what leads
 * to the log lines worth reading; `submitCorrelationId` belongs to the POST
 * that reported it. Conflating them would make every report point at the
 * report itself (ADR-0056).
 *
 * **The trust boundary is a property of this class, not of a controller.**
 * Everything the server can derive itself, the server derives and
 * overwrites: `route`, `release`, `commit`, `brand`, `event`, `userId`,
 * `locale`, `timezone`, `userAgent`, `receivedAt`, `submitCorrelationId`.
 * What only the browser knows stays client-supplied and is validated rather
 * than believed — the URL and the visit buffer are length-bounded, the two
 * client correlation ids must match {@see self::CORRELATION_ID} or they are
 * nulled (D-SUP-04).
 *
 * **What is deliberately absent** (design §11, D-SUP-08): no IP address
 * anywhere — the rate limiter hashes it and forgets it — no cookies, no
 * local storage, no form contents of the reporting page, no screenshot.
 * The dialog lists these field names to the person before they send, which
 * is only honest if the list stays the whole truth.
 *
 * `commit` is null until the image build passes a sha (flagged, §14).
 */
#[TypeScript]
final class SupportContextData extends Data
{
    /**
     * What an id has to look like to be kept. Deliberately a copy of the
     * host's own `CorrelationId` rule rather than an import: a module may
     * not depend on the host application layer (ADR-0003, enforced by
     * `tests/Unit/ArchitectureTest.php`), and the host's constant is
     * private. If the host ever widens its rule, this one widens with it —
     * a mismatch only ever drops an id, never accepts a bad one.
     */
    public const string CORRELATION_ID = '/^[A-Za-z0-9._-]{8,64}$/';

    /**
     * The label a report filed on the operator console carries wherever a
     * brand slug would otherwise stand — in this snapshot and as the
     * `brand` metric label. Never a real slug, so it cannot collide.
     */
    public const string CONSOLE_BRAND = 'console';

    /**
     * @param  list<SupportVisitData>  $recentVisits
     */
    public function __construct(
        // Client, validated: the page that misbehaved.
        public readonly ?string $pageCorrelationId,
        // Server, authoritative: the POST that reported it.
        public readonly ?string $submitCorrelationId,
        public readonly ?string $url,
        // Server: resolved from the URL by the router, null when it resolves
        // to nothing we own.
        public readonly ?string $route,
        public readonly ?string $previousUrl,
        public readonly ?string $previousCorrelationId,
        #[DataCollectionOf(SupportVisitData::class)]
        public readonly array $recentVisits,
        public readonly ?string $release,
        public readonly ?string $commit,
        // Server: the brand slug, or CONSOLE_BRAND on the operator host.
        public readonly ?string $brand,
        public readonly ?string $event,
        public readonly ?string $userId,
        public readonly ?string $locale,
        public readonly ?string $timezone,
        public readonly ?string $userAgent,
        public readonly ?SupportViewportData $viewport,
        public readonly ?string $clientSentAt,
        public readonly ?string $receivedAt,
        public readonly ?string $lastClientError,
    ) {}

    /**
     * An empty snapshot — the shape a request carries before anything is
     * known about it, and what the tests and factories start from.
     *
     * Named `of*` rather than `from*` on purpose: laravel-data treats every
     * public static `from*` method as a magic creation method and would
     * route the Eloquent JSON cast through it (`.ai/rules/data.md`).
     */
    public static function ofNothing(): self
    {
        return new self(
            pageCorrelationId: null,
            submitCorrelationId: null,
            url: null,
            route: null,
            previousUrl: null,
            previousCorrelationId: null,
            recentVisits: [],
            release: null,
            commit: null,
            brand: null,
            event: null,
            userId: null,
            locale: null,
            timezone: null,
            userAgent: null,
            viewport: null,
            clientSentAt: null,
            receivedAt: null,
            lastClientError: null,
        );
    }

    /**
     * The same snapshot with everything a person could be recognised by
     * removed (design §10.5). The technical fields that describe the
     * *incident* — both correlation ids, route, release, brand — survive,
     * because the row stays the operator's record of what went wrong; the
     * ones that describe the *device* do not.
     */
    public function withoutPersonalData(): self
    {
        return new self(
            pageCorrelationId: $this->pageCorrelationId,
            submitCorrelationId: $this->submitCorrelationId,
            url: $this->url,
            route: $this->route,
            previousUrl: $this->previousUrl,
            previousCorrelationId: $this->previousCorrelationId,
            recentVisits: [],
            release: $this->release,
            commit: $this->commit,
            brand: $this->brand,
            event: $this->event,
            userId: null,
            locale: $this->locale,
            timezone: $this->timezone,
            userAgent: null,
            viewport: null,
            clientSentAt: $this->clientSentAt,
            receivedAt: $this->receivedAt,
            lastClientError: null,
        );
    }

    /**
     * The export shape (design §10.5): everything the person sent us about
     * their own report, minus the user agent — a browser fingerprint tells
     * the reader nothing they asked for and is the one field an export
     * would hand out for free.
     *
     * @return array<string, mixed>
     */
    public function toExportArray(): array
    {
        $context = $this->toArray();
        unset($context['userAgent']);

        return $context;
    }
}
