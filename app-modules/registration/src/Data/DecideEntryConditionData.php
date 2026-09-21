<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The console's decision on one declaration (spec §9.1): verify, refuse or
 * waive it, with a bounded reason.
 *
 * `state` is the assertion-state enum minus `asserted`, which is what the
 * registrant wrote rather than a decision — `rules()` narrows it here and
 * `DecideEntryConditionAssertion` refuses it again, because a tinker session
 * gets the same answer the console does.
 *
 * `reasonCode` is an enum and never free text: this column is read back
 * years later and is exported to the subject under Art. 15, so an operator's
 * own sentence about somebody's student ID has no business in it (T-11).
 */
#[TypeScript]
final class DecideEntryConditionData extends Data
{
    public function __construct(
        #[Required]
        public readonly EntryConditionAssertionState $state,

        #[Nullable]
        public readonly ?EntryConditionReasonCode $reasonCode = null,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'state' => ['required', 'in:verified,refused,waived'],
            'reasonCode' => ['nullable', 'in:document_missing,document_invalid,operator_waived'],
        ];
    }
}
