<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the ticket manager (#292 §4.4): who holds it, whether that is
 * the manager themselves, whether the door has already seen it (which
 * closes "take back"), the live invitation if any, and the seat.
 *
 * The holder is a handle (ADR-0065) — `@username`, null while unassigned
 * or until the projection knows it; the display name never ships here.
 */
#[TypeScript]
final class ManagedAdmissionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly string $state,
        public readonly ?string $holderUserId,
        public readonly ?string $holderUsername,
        public readonly bool $isSelf,
        public readonly bool $checkedIn,
        public readonly ?InviteStateData $invite,
        public readonly ?TicketSeatData $seat,
    ) {}
}
