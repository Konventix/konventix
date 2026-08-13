<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * How often one admission may be admitted (D-ADM-03). **The event** carries
 * this knob; checkpoints stay policy-free — a checkpoint enforces whatever the
 * event says, it never holds an opinion of its own (ADR-0033).
 *
 * Deliberately re-declared here instead of imported from
 * `Konventix\Event\Enums\ReentryPolicy`: Admission may not depend on Event's
 * classes (ADR-0004), and the value travelling on `EventReentryPolicyChanged`
 * is the contract — the same reasoning that keeps `'admission'` a string
 * literal in `IssueAdmissionsForRegistration`. The two enums must stay
 * value-compatible; `tests/EntranceEventProfileTest.php` asserts that.
 */
enum ReentryPolicy: string
{
    case OnceOnly = 'once_only';
    case OncePerDay = 'once_per_day';
    case Unlimited = 'unlimited';

    /**
     * Whether an admission with the given accepted check-in history may be
     * admitted again on `$day`. The whole point of D-ADM-03 living on the
     * event is that this is decidable at the door from local state alone.
     *
     * @param  list<string|null>  $acceptedDayIds  event-day ids of prior accepted check-ins
     */
    public function admitsAgain(array $acceptedDayIds, ?string $day): bool
    {
        return match ($this) {
            self::Unlimited => true,
            self::OnceOnly => $acceptedDayIds === [],
            self::OncePerDay => ! in_array($day, $acceptedDayIds, true),
        };
    }
}
