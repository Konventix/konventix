<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Models\Passkey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the "Passkeys" section on the Security settings page (ADR-0009):
 * the name the person gave it, the authenticator the package could label
 * from its AAGUID (null for an unknown or anonymised one), and two instants.
 * Never the credential itself — the public key and signature counter stay
 * server-side.
 */
#[TypeScript]
final class PasskeyData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $authenticator,
        public readonly ?string $lastUsedAt,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(Passkey $passkey): self
    {
        return new self(
            id: $passkey->id,
            name: $passkey->name,
            authenticator: $passkey->authenticator,
            lastUsedAt: $passkey->last_used_at?->toIso8601String(),
            createdAt: $passkey->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
