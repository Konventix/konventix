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
 * tournaments stores none of this. Unlike the eligibility and name
 * projections — which are deliberately ungated so a brand switching
 * Competition on mid-event finds them already filled — this one is cheap to
 * miss: door presence matters for the hours of one event, and #298's second
 * acceptance criterion asks for the short circuit by name.
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
     * **`AdmissionIssued` is on this list although design §6 names only
     * `AdmissionHolderAssigned`.** `AdmissionHolderAssigned` is emitted when
     * an initially *unassigned* admission is claimed (ADR-0032); the ordinary
     * case — somebody buys a ticket for themselves — names the holder on
     * `AdmissionIssued` and never emits a second event. Listening to the
     * design's list alone would leave `holder_user_id` null for most people
     * at the door, and every `requires_event_check_in` competition would
     * refuse its whole field. Recorded as a deviation in the design's §14.
     */
    public function handleIssued(AdmissionIssued $event): void
    {
        $this->upsert($event->admissionId, $event->brandId, [
            'event_id' => $event->eventId,
            'holder_user_id' => $event->holderUserId,
        ]);
    }

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
     * appear in it. A revoked one is removed rather than stored.
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
