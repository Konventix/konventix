<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Platform\Modules\ModuleGate;

/**
 * Keeps `competition_admission_presence` current (design §6): which person
 * holds which admission, and whether the door has seen it.
 *
 * A competition with `requires_event_check_in` may only check a team in once
 * every roster member has been scanned at the event entrance. Admission owns
 * that fact and this module may not read its tables (ADR-0004), so the fact
 * arrives the only sanctioned way — on Admission's public events. The
 * `Konventix\Admission\Events\` imports here are that sanctioned exception.
 *
 * **This is not a second copy of Admission's own presence table.** Admission
 * built one for the live hub, answering "who is inside right now?"; this one
 * answers "has the door ever seen this person at this event?" and is fed from
 * the same public events rather than by reaching across the boundary.
 *
 * Every handler is an upsert on the admission's own ULID, so creation, holder
 * changes and a scan converge on one row in any arrival order — a scan heard
 * before the issue (a replay out of sequence) creates the row with what it
 * knows and the holder fills in later. That is what makes the projection
 * idempotent and replayable (ADR-0004).
 *
 * **Gated on the *event's* brand** (ADR-0003), not the request's: a scan at
 * the door runs on the entrance host, a revocation on a webhook, and neither
 * carries Competition's brand in context. A brand that does not run
 * tournaments stores none of this, and #298's second acceptance criterion
 * asks for the short circuit by name.
 *
 * **What the gate costs is not small, and this is the honest version.** The
 * eligibility and name projections are deliberately ungated so a brand that
 * switches Competition on mid-event finds them already filled. This one
 * cannot be caught up the same way: the backfill event
 * `AdmissionOwnershipAnnounced` carries who holds an admission and whether it
 * still stands, and **no scan timestamp at all**. So a brand that enables
 * `competition` after the doors opened gets its rows and its holders back and
 * never gets `checked_in_at` back — every `requires_event_check_in` team is
 * refused until each of its members is physically re-scanned at the entrance.
 * The gate is mandated (ADR-0003, #298) and stays; the recovery path is an
 * operator-documentation question, not a silent one.
 *
 * Registered explicitly in `CompetitionServiceProvider::registerListeners()`;
 * modular's event discovery is off and must stay off (.ai/rules/listeners.md).
 * `ModuleGate` is resolved inside the handler, never constructor-injected —
 * a listener built by the boot-time dispatcher would otherwise hold the
 * worker's container under Octane (.ai/rules/listeners.md).
 */
final class ProjectAdmissionPresence
{
    /**
     * **`AdmissionIssued` is an addition to §6's list, not a replacement for
     * it.** `AdmissionHolderAssigned` stays the main path for an ordinary
     * attendee and §6 is right about that: `IssueAdmissionsForRegistration`
     * writes `holder_user_id` **null** on every registration-derived
     * admission and emits `AdmissionIssued(holderUserId: null)`; the holder
     * is named later, by `ClaimAdmission` or `AssignHolder`, which is what
     * emits `AdmissionHolderAssigned` (ADR-0032).
     *
     * `AdmissionIssued` earns its place for the one case that never emits a
     * second event: **a crew badge**. `IssueCrewAdmission` creates the
     * admission already `Assigned` — a badge belongs to a named person from
     * the moment it exists — and names the holder on `AdmissionIssued` alone.
     * Without this handler no crew member would ever be at the door as far as
     * this read model is concerned.
     *
     * The other addition, `AdmissionOwnershipAnnounced`, is the only way an
     * admission that predates this read model enters it at all (see
     * `handleOwnershipAnnounced()`). Resources' `ProjectAdmission` consumes
     * the identical five events for the identical two reasons. Recorded in
     * the design's §14.
     */
    public function handleIssued(AdmissionIssued $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
        ]);
    }

    /**
     * **The main path, and the one §6 names.** An ordinary attendee's
     * admission is issued with no holder and gets one here, when the ticket
     * is claimed or an organizer assigns a name to it. Nearly every row in
     * this table learns its `holder_user_id` from this handler; the other two
     * cover a crew badge and the backfill.
     */
    public function handleHolderAssigned(AdmissionHolderAssigned $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
        ]);
    }

    /**
     * The admission lost its holder. The row stays and so does the scan: the
     * *admission* was at the door, and the next person named on it inherits
     * nothing about themselves — `holder_user_id` is what the roster check
     * matches on, so clearing it is enough to stop counting the old holder.
     *
     * Admission refuses this after a check-in, so in practice a scanned
     * admission never reaches here at all.
     */
    public function handleHolderUnassigned(AdmissionHolderUnassigned $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'event_id' => $event->eventId,
            'holder_user_id' => null,
        ]);
    }

    /**
     * The door accepted the admission. Only direction `in` counts: an `out`
     * scan is somebody stepping outside for a cigarette, and the question
     * this table answers is "has the door seen them", not "are they inside
     * right now" — that one is Admission's own presence table, for the hub.
     *
     * The timestamp is the *first* `in` scan and is never moved by a later
     * one: a re-entry does not make somebody more present, and keeping the
     * first makes a replay converge on the same row whatever order it takes.
     */
    public function handleCheckedIn(AdmissionCheckedIn $event): void
    {
        if ($event->direction !== 'in') {
            return;
        }

        $row = $this->find($event->admissionId, $event->brandId);

        if ($row === null) {
            return;
        }

        $row->forceFill([
            'brand_id' => $event->brandId,
            'event_id' => $event->eventId,
            'checked_in_at' => $row->checked_in_at ?? $event->scannedAt,
        ])->save();
    }

    /**
     * A revoked admission admits nobody, so the row goes (design §6:
     * "cleared by `AdmissionRevoked`"). Deleting rather than nulling the
     * timestamp: the holder's other admissions, if any, still stand on their
     * own rows, and a row that says "scanned, but void" is a trap for the
     * next reader.
     */
    public function handleRevoked(AdmissionRevoked $event): void
    {
        if (! $this->gateIsOpen($event->brandId)) {
            return;
        }

        AdmissionPresence::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->whereKey($event->admissionId)
            ->delete();
    }

    /**
     * The backfill (#292's `admission:announce-ownership`): every admission
     * that existed before this read model did needs one announcement to
     * appear in it, and this is the **only** way such a row ever gets here —
     * the issue and the claim happened before anything was listening. A
     * revoked one is removed rather than stored.
     *
     * It restates who holds an admission and whether it stands. It does
     * **not** carry a scan timestamp, which is why the class docblock says
     * what a missed projection costs.
     */
    public function handleOwnershipAnnounced(AdmissionOwnershipAnnounced $event): void
    {
        if (! $this->gateIsOpen($event->brandId)) {
            return;
        }

        if ($event->state === 'revoked') {
            AdmissionPresence::withoutBrandScope()
                ->where('brand_id', $event->brandId)
                ->whereKey($event->admissionId)
                ->delete();

            return;
        }

        $this->upsert($event->admissionId, $event->brandId, [
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
        ]);
    }

    /**
     * `brand_id` is stated from the payload through `forceFill()`, never mass
     * assigned and never taken from ambient context: this runs off any host,
     * usually with no brand resolved at all (.ai/rules/models.md, ADR-0010).
     *
     * @param  array<string, string|null>  $values
     */
    private function upsert(string $admissionId, string $brandId, array $values): void
    {
        $row = $this->find($admissionId, $brandId);

        if ($row === null) {
            return;
        }

        $row->forceFill(['brand_id' => $brandId, ...$values])->save();
    }

    /**
     * The existing row, or a new unsaved one — or null when the module is off
     * for this brand, which is every handler's short circuit (#298).
     */
    private function find(string $admissionId, string $brandId): ?AdmissionPresence
    {
        if (! $this->gateIsOpen($brandId)) {
            return null;
        }

        /** @var AdmissionPresence|null $row */
        $row = AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->first();

        return $row ?? new AdmissionPresence(['admission_id' => $admissionId]);
    }

    private function gateIsOpen(string $brandId): bool
    {
        return app(ModuleGate::class)->isActiveForBrand('competition', $brandId);
    }
}
