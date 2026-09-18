<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Data\TicketPageData;
use Konventix\Admission\Data\TicketSeatData;
use Konventix\Admission\Documents\TicketQr;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * One ticket, on screen (#292 §4.5): the event band with the banner, the
 * holder band with the scan code, the seat, and the ways out — PDF,
 * calendar, seat picker. Reached by the holder and by the manager
 * (`buyer_user_id`); anybody else gets the 403 the PDF routes give.
 *
 * `current()`, never `rotate()`: opening the page must not change the
 * code already printed (`.ai/rules/tickets.md`).
 */
final class TicketPageController
{
    public function __construct(
        private readonly IssueAdmissionToken $tokens,
        private readonly TicketQr $qr,
        private readonly OperatorDateTimeFormat $dates,
        private readonly MediaLibrary $media,
    ) {}

    public function show(Request $request, Admission $admission): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $isHolder = $admission->holder_user_id === $userId;
        $isManager = $admission->buyer_user_id === $userId;

        abort_unless($isHolder || $isManager, 403);
        abort_if($admission->state === AdmissionState::Revoked, 404, __('This ticket has been revoked.'));

        $event = EventSummary::query()->where('event_id', $admission->event_id)->first();
        $banner = $event?->banner_media_id === null ? null : $this->media->referenceFor($event->banner_media_id);
        $holder = $admission->holder_user_id === null ? null : Person::query()->find($admission->holder_user_id);
        $seat = AdmissionSeat::query()->where('admission_id', $admission->id)->first();
        $checkedIn = CheckIn::query()
            ->where('admission_id', $admission->id)
            ->where('result', CheckInResult::Accepted)
            ->exists();

        return Inertia::render('admission/tickets/Show', (new TicketPageData(
            id: $admission->id,
            eventId: $admission->event_id,
            eventName: $event?->name,
            eventSlug: $event?->slug,
            eventWhen: $this->eventWhenFor($event),
            venueLabel: $event?->venue_label,
            bannerUrl: $banner['url'] ?? null,
            bannerAlt: $banner['altText'] ?? $event?->name,
            role: str_replace('_', ' ', $admission->role),
            state: $admission->state->value,
            validRange: $this->validRangeFor($admission),
            holderName: $holder->display_name ?? 'Unassigned',
            isHolder: $isHolder,
            isManager: $isManager,
            checkedIn: $checkedIn,
            qrSvg: $this->qr->svg($this->tokens->current($admission)),
            doorCode: $this->doorCode($admission->id),
            seat: $seat === null ? null : new TicketSeatData(
                bookingId: $seat->booking_id,
                placeRef: $seat->place_ref,
                placeLabel: $seat->place_label,
            ),
            // Resources decides who may pick (§6.3); until its per-admission
            // picker exists this is off, and the row shows the seat only.
            canPickSeat: false,
            registrationId: $admission->registration_id,
        ))->toArray());
    }

    /**
     * Wall clock as stored (ADR-0027), never converted — the same two
     * columns `TicketDocumentRenderer::eventWhenFor()` prints.
     */
    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null || $event->starts_at === null || $event->ends_at === null) {
            return null;
        }

        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at),
            $this->dates->dateTime($event->ends_at),
            $event->timezone,
        );
    }

    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return 'All event days';
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }

    private function doorCode(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }
}
