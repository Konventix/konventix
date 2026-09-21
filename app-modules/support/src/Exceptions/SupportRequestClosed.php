<?php

declare(strict_types=1);

namespace Konventix\Support\Exceptions;

use RuntimeException;

/**
 * A reporter wrote into a thread that is finished (design §4.2: "a reporter
 * may add external messages while the request is not terminal").
 *
 * A domain exception rather than a silent drop, for the reason
 * {@see InvalidSupportTransition} gives: the page hides the reply form on a
 * terminal report, so reaching this means the report was closed while the
 * form stood open — somebody typed a paragraph into a box that had already
 * stopped accepting it, and the one unacceptable answer is to take it and
 * throw it away. The controller turns this into a 422 on `body`, which is
 * where the text they wrote is still on screen.
 *
 * Staff are deliberately not subject to it: `PostSupportMessage` still
 * accepts a note or a closing reply on a resolved report, which is how a
 * "reopened, see below" is written at all.
 */
final class SupportRequestClosed extends RuntimeException
{
    public static function forReference(string $reference): self
    {
        return new self(sprintf(
            'Support request [%s] is closed and takes no further replies.',
            $reference,
        ));
    }
}
