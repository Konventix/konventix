<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The invite form (#292 §4.4, ADR-0009): an address binds the link to one
 * recipient and sends it; no address mints a bearer link the manager copies
 * and passes on however they like.
 */
#[TypeScript]
final class InviteData extends Data
{
    public function __construct(
        #[Nullable, Email('rfc'), Max(255)]
        public readonly ?string $email = null,
    ) {}
}
