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
use Konventix\Admission\Support\TicketReference;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Konventix\Platform\Slots\SlotRegistry;

/**
 * One ticket, on screen (#292 §4.5): the event band with the banner, the
 * holder band with the scan code, the seat, and the ways out — PDF,
 * calendar, seat picker. Reached by the holder and by the manager
 * (`buyer_user_id`); anybody else gets the 403 the PDF routes give.
 *
 * `current()`, never `rotate()`: opening the page must not change the
 * code already printed (`.ai/rules/tickets.md`).
 *
 * The holder is a handle (ADR-0065): the page shows `@username`, the PDF
 * the display name.
 */
final class TicketPageController
{
    public function __construct(
        private readonly IssueAdmissionToken $tokens,
        private readonly TicketQr $qr,
        private readonly OperatorDateTimeFormat $dates,
        private readonly MediaLibrary $media,
        private readonly SlotRegistry $slots,
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
            holderUsername: $holder?->username,
            isHolder: $isHolder,
            isManager: $isManager,
            checkedIn: $checkedIn,
            qrSvg: $this->qr->svg($this->tokens->current($admission)),
            doorCode: TicketReference::of($admission->id),
            seat: $seat === null ? null : new TicketSeatData(
                bookingId: $seat->booking_id,
                placeRef: $seat->place_ref,
                placeLabel: $seat->place_label,
            ),
            // The manager's way back to the registration, and the only key
            // to its combined file (#316): a holder who did not buy the
            // ticket gets null, so the page cannot even name the bundle
            // route `TicketDownloadController::buyer()` would refuse them.
            registrationId: $isManager ? $admission->registration_id : null,
        ))->toArray() + [
            // Resources decides who may pick the seat (§6.3) and contributes
            // the button through this slot — Admission never asks its
            // tables (ADR-0004, ADR-0003 enforcement point 3).
            'slots' => $this->slots->for('admission.ticket.show', ['admissionId' => $admission->id])
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
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
            return __('All event days');
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }
}
