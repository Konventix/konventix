<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registration;

/**
 * Registration discharging Compliance's retention rule for entry-condition
 * declarations (D-REG-23, spec §11).
 *
 * The clock is the **registration's own**, started by `EventConcluded` —
 * which is why this listener only acts on a signal that names an event. An
 * assertion is not invoice data and must not inherit the ten-year
 * bookkeeping floor: the invoice carries the price it charged and does not
 * need the reason beside somebody's name for a decade.
 *
 * Compliance publishes the rule and never touches this table; a listener
 * that does not recognise the category ignores the event, which is the whole
 * shape of the retention contract (ADR-0004).
 *
 * Deleted by the boundary rather than "everything I have", so the daily
 * re-emission stays harmless and idempotent — and the delete is scoped to
 * the registrations of that event, never to every assertion of the brand.
 *
 * Registered explicitly in `RegistrationServiceProvider` — modular event
 * discovery is off and must stay off (.ai/rules/listeners.md).
 */
final class PruneEntryConditionAssertions
{
    public const string CATEGORY = 'registration.entry-condition-assertions';

    public function handle(RetentionPeriodElapsed $event): void
    {
        if ($event->dataCategory !== self::CATEGORY || $event->eventId === null) {
            return;
        }

        EntryConditionAssertion::withoutBrandScope()
            ->when($event->brandId !== null, fn ($query) => $query->where('brand_id', $event->brandId))
            ->whereIn('registration_id', Registration::withoutBrandScope()
                ->where('event_id', $event->eventId)
                ->select('id'))
            ->where('asserted_at', '<', $event->cutoffAt)
            ->delete();
    }
}
