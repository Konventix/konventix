<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\ProfileValidationRules;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a provider could not tell us and the person has to (ADR-0009: one Data
 * object per form, and the only source of its rules and its TypeScript type).
 *
 * Steam asserts no email address, so an account made this way still needs one
 * — for verification, password reset, and every notice Messaging sends. The
 * display name is asked for rather than taken from the provider because a
 * deployment without a Steam Web API key has no profile to read, and because a
 * Steam persona is not necessarily what somebody wants shown at an event.
 *
 * No password field, deliberately: this account is reached through the
 * provider. A password can be adopted later through the ordinary reset flow.
 *
 * Reuses the same rule Concerns as registration, so the two ways into an
 * account cannot drift on what a valid name or address looks like — including
 * `emailRules()`' uniqueness check, which is what refuses a signup naming an
 * address that already exists. See the controller for why that refusal is
 * correct here rather than an invitation to link the accounts.
 */
#[TypeScript]
final class CompleteSocialSignupData extends Data
{
    use ProfileValidationRules;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => self::nameRules(),
            'email' => self::emailRules(),
        ];
    }
}
