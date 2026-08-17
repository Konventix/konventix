<?php

declare(strict_types=1);

namespace Konventix\Compliance\Exceptions;

use Konventix\Compliance\Models\PolicyVersion;
use RuntimeException;

/**
 * Thrown by `UpdatePolicyVersion` when the version being edited has left the
 * draft state. Consent records point at a version id and mean "agreed to
 * this text"; editing the text afterwards would rewrite what people agreed
 * to. Draft a new version instead.
 */
final class PublishedPolicyVersionIsImmutableException extends RuntimeException
{
    public static function for(PolicyVersion $version): self
    {
        return new self(sprintf(
            'Policy version [%s] is %s and can no longer be edited — draft a new version instead.',
            $version->version,
            $version->state->value,
        ));
    }
}
