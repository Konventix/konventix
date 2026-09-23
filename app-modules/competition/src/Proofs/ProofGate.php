<?php

declare(strict_types=1);

namespace Konventix\Competition\Proofs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Models\Competition;
use Konventix\Platform\Media\MediaLibrary;
use Throwable;

/**
 * The competition's proof policy, applied once for both things that can carry
 * a proof (design §8.7): a head-to-head result report (§8.2) and a leaderboard
 * score entry (§8.3).
 *
 * One class rather than a guard in each Action, because §8.1's proof knob is
 * read in three places that must not drift: the upload door (`StoreProofImage`,
 * §8.7), the score-entry path (§8.3) and the match-report path (§8.2).
 *
 * **The upload door is the one that matters for `none`.** A `proof_media_id`
 * is a ULID, which means the file is already written by the time a submission
 * guard could refuse it: a participant would upload successfully and then have
 * their whole score rejected over an attachment, leaving an orphaned object
 * behind. So the policy is asked *before* a byte is stored, and the guards in
 * the two submission Actions stay as backstops for anything that reached them
 * another way.
 *
 * ## What this does not do
 *
 * It does not store the file. Proofs go through Platform's media library
 * (ADR-0035) and a module that built its own upload path would be exactly the
 * workaround §8.7 forbids; `StoreProofImage` authorises and then hands the
 * file to Platform's own `StoreMediaUpload`.
 *
 * What §8.7 asks for on top — **per-object read authorization**, so a proof is
 * "readable only by the two sides and the desk through a proxied route" — the
 * library does not have: `MediaFileController` is deliberately
 * unauthenticated and brand-agnostic, and there is no private-disk flag per
 * item. §8.7 says what follows in so many words: "that becomes a Platform
 * change *before* proofs ship, not a Competition workaround". It is recorded
 * as owed in the design's §14 and in this module's TODO, and nothing here
 * will have to move when it lands — a media ULID is a media ULID whichever
 * door it came in through.
 *
 * **A proof id must name one of the brand's own media items.** The id is
 * participant-supplied, and a ULID from anywhere in the deployment — another
 * brand's sponsor artwork, another team's proof — would otherwise be stored
 * and shown to the opposing side and the desk as this side's evidence.
 * `resolve()` refuses it, so the refusal rolls back the submission it came
 * with.
 *
 * Usage references are recorded (ADR-0035) so the library knows a proof is
 * referenced. That write stays best effort: by the time it runs the id has
 * already been checked, and bookkeeping for the library's future cleanup is
 * not a reason to roll back a result somebody played for.
 */
final class ProofGate
{
    public function __construct(
        private readonly MediaLibrary $media,
    ) {}

    /**
     * The proof media id that should be stored, given what was submitted.
     *
     * Three outcomes, and the policy decides which: the submitted id, `null`
     * where none was submitted and none is owed, or a refusal — a required
     * proof that is missing, a proof sent to a competition that takes none,
     * or an id that is not one of `$brandId`'s media items.
     *
     * **The desk is exempt from the demand** (design §14, fix round). A
     * `required` policy is what participants owe; the desk has no upload
     * control (#322) and the participant pages tell people the desk collects
     * the proof, so an organizer entering a result without one is the
     * designed path, not a gap. A proof the desk does send is still checked
     * like any other. The refusals speak the caller's language (ADR-0001):
     * English to the desk, German to a participant.
     *
     * @param  string  $brandId  the brand the submission belongs to, from its parent row (ADR-0010)
     * @param  string  $field  the form field a refusal is attributed to
     * @param  bool  $asOrganizer  the desk's path: no proof is demanded, and refusals are English
     *
     * @throws ValidationException when a required proof is missing, an
     *                             unsolicited one is refused, or the id names
     *                             media the brand does not own
     */
    public function resolve(
        ?Competition $competition,
        string $brandId,
        ?string $proofMediaId,
        string $field = 'proofMediaId',
        bool $asOrganizer = false,
    ): ?string {
        // A competition that cannot be resolved is not a reason to refuse a
        // result: `Optional` is the reading that neither demands nor drops.
        $policy = $competition->proof_policy ?? ProofPolicy::Optional;

        if ($proofMediaId === null) {
            if ($policy->demandsProof() && ! $asOrganizer) {
                throw ValidationException::withMessages([
                    $field => __('Dieses Turnier braucht zu jedem Ergebnis ein Beweisbild.'),
                ]);
            }

            return null;
        }

        if (! $policy->acceptsProof()) {
            throw ValidationException::withMessages([
                $field => $asOrganizer
                    ? __('This competition does not take proof images.')
                    : __('Dieses Turnier nimmt keine Beweisbilder an.'),
            ]);
        }

        // Unknown and foreign are one refusal: telling them apart would
        // answer whether a ULID names another brand's media.
        if (! $this->media->belongsToBrand($proofMediaId, $brandId)) {
            throw ValidationException::withMessages([
                $field => $asOrganizer
                    ? __('That proof image is not one of this brand\'s media items.')
                    : __('Dieses Beweisbild ist unbekannt. Lade es bitte erneut hoch.'),
            ]);
        }

        return $proofMediaId;
    }

    /**
     * Record that a row of this module references a proof (ADR-0035).
     *
     * Best effort on purpose. The reference is bookkeeping for the library's
     * future cleanup, and neither an id that belongs to no brand nor a
     * library that throws is worth rolling back the result the participant
     * just reported — both are logged and the write stands.
     *
     * **In a savepoint of its own** (final review, part 2, M2; deferred
     * T5 M-4). The callers run this inside their `DB::transaction()`, and on
     * Postgres a failed statement aborts the whole transaction: without the
     * savepoint the catch below would swallow the error, and the caller's
     * next statement would fail with 25P02 and roll the result back as a 500
     * — the opposite of "the write stands". The nested `DB::transaction()`
     * rolls back to its savepoint before rethrowing, so the outer transaction
     * is still usable when the catch runs.
     */
    public function recordUsage(?string $proofMediaId, string $brandId, string $entityType, string $entityId): void
    {
        if ($proofMediaId === null) {
            return;
        }

        try {
            DB::transaction(function () use ($proofMediaId, $brandId, $entityType, $entityId): void {
                if (! $this->media->belongsToBrand($proofMediaId, $brandId)) {
                    Log::warning('A competition proof references media that is not this brand\'s.', [
                        'media_id' => $proofMediaId,
                        'brand_id' => $brandId,
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                    ]);

                    return;
                }

                $this->media->recordUsage($proofMediaId, 'competition', $entityType, $entityId);
            });
        } catch (Throwable $exception) {
            Log::warning('A competition proof usage reference could not be recorded.', [
                'media_id' => $proofMediaId,
                'brand_id' => $brandId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
