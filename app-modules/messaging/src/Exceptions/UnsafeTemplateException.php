<?php

declare(strict_types=1);

namespace Konventix\Messaging\Exceptions;

use RuntimeException;

/**
 * A template override contains something `TemplateOverrideGuard` does not
 * allow-list: an unrecognised directive, an echo that is not a plain field
 * reference, a raw PHP tag, or a directive glued to a neighbouring word.
 * Carries the offending fragment and its byte offset so the caller can build
 * a message that points at what was refused, not just that something was.
 */
final class UnsafeTemplateException extends RuntimeException
{
    public function __construct(public readonly string $fragment, public readonly int $position)
    {
        parent::__construct(sprintf('Disallowed template fragment at position %d: %s', $position, $fragment));
    }

    /**
     * The one message both `SaveTemplateOverride` (keyed `subject`/`body`)
     * and `TemplateAdminController::preview()` (a flat `{message}`) show —
     * stated once so the two never drift apart.
     */
    public function userMessage(): string
    {
        return __(
            'Overrides may only use simple echoes ({{ $name }}) and if/unless/foreach directives on payload fields — «:fragment» is not allowed.',
            ['fragment' => $this->fragment],
        );
    }
}
