<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * A retention period ran out for one data category (SPEC "Public contract").
 *
 * Compliance publishes the rule and emits this; the module that holds the
 * data deletes its own rows. Compliance never deletes another module's data
 * and never learns which tables the category covers — a listener that does
 * not recognise `dataCategory` ignores the event.
 *
 * `cutoffAt` is the boundary, not the moment of emission: everything in the
 * category older than it is out of retention. Consumers must delete by that
 * boundary rather than by "everything I have", which is what keeps the daily
 * re-emission below harmless.
 *
 * `eventId` is set only for `event_concluded` clocks, where the period hangs
 * off one event rather than off each row's own age.
 *
 * The sweep re-emits every day for as long as a rule stays elapsed. That is
 * deliberate: a delete-older-than is idempotent, so the alternative — a
 * "already emitted" flag per rule — would add state whose only job is to
 * suppress a harmless repeat, and whose failure mode is data that never gets
 * deleted.
 */
final class RetentionPeriodElapsed
{
    public function __construct(
        public readonly string $ruleId,
        public readonly ?string $brandId,
        public readonly string $dataCategory,
        public readonly string $cutoffAt,
        public readonly ?string $eventId = null,
    ) {}
}
