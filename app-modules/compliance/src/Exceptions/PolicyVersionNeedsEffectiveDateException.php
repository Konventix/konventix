<?php

declare(strict_types=1);

namespace Konventix\Compliance\Exceptions;

use Konventix\Compliance\Models\PolicyVersion;
use RuntimeException;

/**
 * Thrown by `PublishPolicyVersion` when neither the call nor the version
 * itself carries an effective date.
 *
 * It used to fall back to `now()` silently, which published texts nobody had
 * dated: `effective_at == published_at` down to the second, and no record of
 * anyone having decided that. From when a text binds is a legal statement, so
 * it is stated, not inferred — the console asks for it before it offers the
 * publish button.
 */
final class PolicyVersionNeedsEffectiveDateException extends RuntimeException
{
    public static function for(PolicyVersion $version): self
    {
        return new self(sprintf(
            'Policy version [%s] cannot be published without an effective date.',
            $version->version,
        ));
    }
}
