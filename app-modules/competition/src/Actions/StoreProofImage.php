<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Http\UploadedFile;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Platform\Actions\StoreMediaUpload;
use RuntimeException;

/**
 * A participant's proof image, on its way into Platform's media library
 * (design §8.7, ADR-0035).
 *
 * The **door**, not the storage. ADR-0035 is that Platform owns uploading,
 * variants and disks, and that every other module references media by ULID;
 * this Action authorises and then hands the file to Platform's own
 * `StoreMediaUpload`. Nothing here writes a file, knows a disk or builds a
 * path.
 *
 * ## Why the policy is checked here and not only at submission
 *
 * `proof_media_id` is a ULID, so by the time `SubmitScoreEntry` or
 * `ReportMatchResult` could refuse a proof, the image is already stored. A
 * competition whose `proof_policy` is `none` — which is the column's default
 * — would otherwise accept the upload, then reject the participant's entire
 * score over the attachment, and leave an orphaned object behind on a disk
 * nobody is going to sweep. So the refusal happens before a byte is written.
 * The guards in the two submission Actions stay: they are the backstop for a
 * media ULID that arrived some other way.
 *
 * ## Who may
 *
 * Membership in the side, like every other participant action (§11): a proof
 * belongs to an entrant, and the people who may attach one are the people on
 * that entrant's roster. There is no capability — the desk uploads through
 * the console's own media library, which it already has.
 *
 * ## What is still owed
 *
 * §8.7 also asks for the read half: a **private** disk and per-object read
 * authorization, so a proof is "readable only by the two sides and the desk
 * through a proxied route". The library has neither today —
 * `MediaFileController` is unauthenticated by design — and §8.7 is explicit
 * that this "becomes a Platform change *before* proofs ship, not a
 * Competition workaround". Recorded in the design's §14 and this module's
 * TODO. An image stored through this Action is readable by anyone holding its
 * ULID until that lands.
 */
final class StoreProofImage
{
    use GuardsSideMembership;

    /**
     * @return string the media ULID to store on the report or score entry
     */
    public function handle(
        Competition $competition,
        Entrant $entrant,
        UploadedFile $file,
        string $uploadedByUserId,
    ): string {
        if (! $competition->proof_policy->acceptsProof()) {
            throw ResultSubmissionException::takesNoProofs($competition->id);
        }

        if ($entrant->competition_id !== $competition->id || $entrant->brand_id !== $competition->brand_id) {
            throw new RuntimeException(sprintf(
                'Entrant [%s] does not belong to competition [%s].',
                $entrant->id,
                $competition->id,
            ));
        }

        $this->guardIsOnTheRoster($entrant, $uploadedByUserId);

        // Platform's Action, Platform's disk, Platform's variants (ADR-0035).
        // The brand is stated rather than resolved: a proof may be uploaded
        // from the brand site or, later, from the desk on the operator host
        // where no brand is in context (ADR-0029).
        $item = app(StoreMediaUpload::class)->handle(
            brandId: $competition->brand_id,
            file: $file,
            altText: null,
            uploadedByUserId: $uploadedByUserId,
        );

        return (string) $item->getKey();
    }
}
