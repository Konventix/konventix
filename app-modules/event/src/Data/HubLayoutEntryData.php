<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the operator's hub layout (design §6): a registry key
 * ('admission.my-ticket') and whether the card or link it names is shown.
 * Position in the list is the display order, so there is no explicit
 * `order` field to fall out of step with it. Typed once here for the
 * form's props and the generated TypeScript (ADR-0009); the validation
 * rules live on `UpdateHubLayoutData`, which owns the list.
 */
#[TypeScript]
final class HubLayoutEntryData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly bool $visible,
    ) {}
}
