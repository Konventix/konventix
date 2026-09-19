<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A live claim link was withdrawn by its issuer (#292 §9). Not emitted for
 * the implicit revocation `AssignHolder` and a re-issue perform — those are
 * bookkeeping, not somebody changing their mind.
 */
final readonly class ClaimLinkRevoked
{
    public function __construct(
        public string $linkId,
        public string $admissionId,
        public string $brandId,
    ) {}
}
