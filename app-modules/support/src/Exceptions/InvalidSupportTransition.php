<?php

declare(strict_types=1);

namespace Konventix\Support\Exceptions;

use Konventix\Support\Enums\SupportRequestState;
use RuntimeException;

/**
 * A state move the table of design §4.3 does not allow — "resolved" jumping
 * straight back to "received", or a console racing itself.
 *
 * A domain exception rather than a validation error: the console only ever
 * offers the moves {@see SupportRequestState::allowedNext()} returns, so
 * reaching this means the request was crafted or two operators acted at
 * once, and the honest answer is a refusal naming both ends.
 */
final class InvalidSupportTransition extends RuntimeException
{
    public static function between(SupportRequestState $from, SupportRequestState $to): self
    {
        return new self(sprintf(
            'A support request cannot move from [%s] to [%s].',
            $from->value,
            $to->value,
        ));
    }
}
