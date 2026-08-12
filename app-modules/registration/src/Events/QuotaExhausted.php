<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The last unit of a quota was consumed (SPEC.md "Public contract" table).
 * Emitted by `Konventix\Registration\Actions\SubmitRegistration` the moment a
 * quota's `remaining()` reaches zero.
 *
 * `eventId` is the event the consuming `RegistrationType` belongs to — Quota
 * itself carries no `event_id` column, since one quota may be referenced by
 * types across more than one event (SPEC.md "Open questions" — quota
 * composition).
 */
final class QuotaExhausted
{
    public function __construct(
        public readonly string $quotaId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $exhaustedAt,
    ) {}
}
