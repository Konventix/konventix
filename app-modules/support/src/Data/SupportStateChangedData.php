<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind `support.request-state-changed` (design §7):
 * "somebody has picked your report up", "it is fixed", "we will not fix
 * this".
 *
 * Branch-free like every template in this module, which here costs three
 * fields instead of one `@if` over five states. The reason is the same one
 * {@see SupportReceiptData} states: a brand operator may override this
 * template through the console editor, `TemplateOverrideGuard` allows an
 * override no expression at all, and a default that branched would be one
 * the editor cannot save back.
 *
 * `stateDe` / `stateEn` are the state's name in each locale's body, and
 * `noteDe` / `noteEn` the one sentence that says what it means for the
 * person — "we are looking at it" reads nothing like "we have decided not
 * to change this", and a mail that only named the state would leave the
 * reporter to guess which of the two they just got.
 */
final class SupportStateChangedData extends Data
{
    public function __construct(
        public readonly string $reference,
        public readonly string $stateDe,
        public readonly string $stateEn,
        public readonly string $noteDe,
        public readonly string $noteEn,
        /** Absolute: the account page, or the guest's signed link. */
        public readonly string $requestUrl,
    ) {}
}
