<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\SupportRequestState;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `PATCH /admin/support-requests/{request}/state` (design §9.2).
 *
 * The *shape* only: which of the five states the operator asked for. Whether
 * the request may go there is `SupportRequestState::allowedNext()`, enforced
 * by `TransitionSupportRequest` — a rule that belongs to the domain and not
 * to a form, because the same table governs the automatic `received → seen`
 * the first console open performs, which no form ever submits.
 */
#[TypeScript]
final class SupportStateInputData extends Data
{
    public function __construct(
        #[Required]
        public readonly SupportRequestState $state,
    ) {}
}
