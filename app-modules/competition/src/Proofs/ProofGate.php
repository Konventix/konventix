<?php

declare(strict_types=1);

namespace Konventix\Competition\Proofs;

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
 * Usage references are recorded (ADR-0035) so the library knows a proof is
 * referenced, but only for an item that actually belongs to the brand: a
 * bogus ULID is a validation problem for the upload path, not a reason to
 * roll back a result somebody played for.
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
     * proof that is missing, or a proof sent to a competition that takes
     * none.
     *
     * @param  string  $field  the form field a refusal is attributed to
     *
     * @throws ValidationException when a required proof is missing, or an
     *                             unsolicited one is refused
     */
    public function resolve(?Competition $competition, ?string $proofMediaId, string $field = 'proofMediaId'): ?string
    {
        // A competition that cannot be resolved is not a reason to refuse a
        // result: `Optional` is the reading that neither demands nor drops.
        $policy = $competition->proof_policy ?? ProofPolicy::Optional;

        if ($proofMediaId === null) {
            if ($policy->demandsProof()) {
                throw ValidationException::withMessages([
                    $field => __('This competition needs a proof image with every result.'),
                ]);
            }

            return null;
        }

        if (! $policy->acceptsProof()) {
            throw ValidationException::withMessages([
                $field => __('This competition does not take proof images.'),
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
     */
    public function recordUsage(?string $proofMediaId, string $brandId, string $entityType, string $entityId): void
    {
        if ($proofMediaId === null) {
            return;
        }

        try {
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
        } catch (Throwable $exception) {
            Log::warning('A competition proof usage reference could not be recorded.', [
                'media_id' => $proofMediaId,
                'brand_id' => $brandId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
