<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Whether a reported result or a score entry carries a proof image
 * (D-CMP-19, design §8.1). The image itself lives in Platform's media
 * library (ADR-0035).
 *
 * **The three values are the three answers to §8.1's own question** —
 * "whether a report or score entry *may or must* carry a proof image" — so
 * `none` means *may not*, not *ignored*. A `none` that quietly accepted and
 * dropped an image would be behaviourally identical to `optional`, and the
 * column would carry two spellings of one setting. §8.7 wants the same
 * answer from the other end: proofs are participant uploads on a private
 * disk with per-object read authorization, and a competition that takes none
 * should not be accumulating images nobody is authorised to read.
 *
 * Every caller asks these methods rather than comparing cases, and
 * `Proofs\ProofGate` is the only place in the module that reads them — so
 * the score-entry path (§8.3), the match-report path (§8.2) and the upload
 * door (§8.7) cannot drift apart.
 */
enum ProofPolicy: string
{
    case None = 'none';
    case Optional = 'optional';
    case Required = 'required';

    /**
     * Whether a submission **without** a proof is refused.
     */
    public function demandsProof(): bool
    {
        return $this === self::Required;
    }

    /**
     * Whether a proof may be uploaded and stored at all.
     *
     * False is a refusal, at both ends: the upload door turns the file away
     * before it is written (§8.7), and the submission Actions turn a media
     * ULID away as a backstop for anything that reached them another way.
     */
    public function acceptsProof(): bool
    {
        return $this !== self::None;
    }
}
