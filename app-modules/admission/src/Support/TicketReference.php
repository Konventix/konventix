<?php

declare(strict_types=1);

namespace Konventix\Admission\Support;

/**
 * The short ticket reference a guest can read out at a desk (attendee-list
 * design §3, #329): the last eight characters of the admission ULID,
 * upper-cased and grouped `XXXX-XXXX`. The ticket PDF (`door-code`) and the
 * holder's ticket page print it, and the attendee list searches by it — one
 * definition, so the three can never disagree.
 *
 * ULIDs are stored lower-case, and the list matches with
 * `admissions.id ILIKE %…%`, which ignores case. `searchFragment()` therefore
 * only has to drop the hyphen and any whitespace, so `ab12-cd34` and
 * `AB12CD34` find the same ticket.
 */
final class TicketReference
{
    public static function of(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }

    public static function searchFragment(string $typed): string
    {
        return (string) preg_replace('/[\s-]+/', '', $typed);
    }
}
