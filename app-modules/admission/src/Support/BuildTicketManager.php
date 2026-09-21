<?php

declare(strict_types=1);

namespace Konventix\Admission\Support;

use Illuminate\Database\Eloquent\Collection;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Data\InviteStateData;
use Konventix\Admission\Data\ManagedAdmissionData;
use Konventix\Admission\Data\TicketManagerData;
use Konventix\Admission\Data\TicketSeatData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Person;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Spatie\LaravelData\DataCollection;

/**
 * The ticket manager's rows for one registration (#292 §4.4), built for
 * the `registration.mine.show` slot. Four queries for the whole panel —
 * admissions, people, live links, accepted check-ins — never one per row:
 * `Model::shouldBeStrict()` is on and an N+1 here would be one per ticket
 * of a group booking.
 *
 * Null when the registration granted no admissions, or every one has been
 * revoked: the panel then renders nothing rather than an empty heading.
 */
final class BuildTicketManager
{
    public function __construct(
        private readonly IssueAdmissionClaimLink $links,
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
    ) {}

    public function handle(string $registrationId, string $viewerUserId): ?TicketManagerData
    {
        /** @var Collection<int, Admission> $admissions */
        $admissions = Admission::query()
            ->where('registration_id', $registrationId)
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderBy('created_at')
            ->get();

        if ($admissions->isEmpty()) {
            return null;
        }

        $ids = $admissions->pluck('id')->all();

        // Handles, not names (ADR-0065): the page prints `@username`.
        $people = Person::query()
            ->whereIn('id', $admissions->pluck('holder_user_id')->filter()->unique()->all())
            ->pluck('username', 'id');

        $links = AdmissionClaimLink::query()
            ->whereIn('admission_id', $ids)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('admission_id');

        $checkedIn = CheckIn::query()
            ->whereIn('admission_id', $ids)
            ->where('result', CheckInResult::Accepted)
            ->pluck('admission_id')
            ->flip();

        $seats = AdmissionSeat::query()
            ->whereIn('admission_id', $ids)
            ->get()
            ->keyBy('admission_id');

        $zone = $this->brandTimezone->handle($admissions->first()->brand_id);

        $rows = $admissions->map(function (Admission $admission) use ($people, $links, $checkedIn, $seats, $zone, $viewerUserId): ManagedAdmissionData {
            /** @var AdmissionClaimLink|null $link */
            $link = $links->get($admission->id);
            /** @var AdmissionSeat|null $seat */
            $seat = $seats->get($admission->id);

            return new ManagedAdmissionData(
                id: $admission->id,
                role: $admission->role,
                state: $admission->state->value,
                holderUserId: $admission->holder_user_id,
                holderUsername: $admission->holder_user_id === null ? null : ($people->get($admission->holder_user_id) ?? null),
                isSelf: $admission->holder_user_id === $viewerUserId,
                checkedIn: $checkedIn->has($admission->id),
                invite: $link === null ? null : new InviteStateData(
                    intendedEmail: $link->intended_email,
                    expiresAt: $this->dates->dateTime($link->expires_at, $zone),
                    url: $this->links->urlFor($link),
                ),
                seat: $seat === null ? null : new TicketSeatData(
                    bookingId: $seat->booking_id,
                    placeRef: $seat->place_ref,
                    placeLabel: $seat->place_label,
                ),
            );
        })->values()->all();

        return new TicketManagerData(
            registrationId: $registrationId,
            ticketCount: count($rows),
            holdsOne: $admissions->contains(fn (Admission $admission): bool => $admission->holder_user_id === $viewerUserId),
            admissions: new DataCollection(ManagedAdmissionData::class, $rows),
        );
    }
}
