<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\PasswordValidationRules;
use Konventix\Identity\Concerns\ProfileValidationRules;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single source of validation rules, Inertia props shape, and generated
 * TypeScript type for the registration form (ADR-0009). `rules()` reuses the
 * exact same Concerns as the rest of the module rather than duplicating them
 * as validation attributes, so registration and profile-editing can never
 * drift on what a valid name/email/password looks like.
 *
 * ADR-0016/#18: `email` validates through `emailFormatRules()`, not
 * `emailRules()` — no `Rule::unique(User::class)` here, deliberately. A
 * duplicate address must not surface as an ordinary validation error (the
 * ADR requires this path be indistinguishable from success), so the "does
 * this address already exist" question is answered by `CreateNewUser`
 * instead, which sends the existing account a notice mail rather than the
 * requester a validation error. `AccountRegistrationController` is the
 * `register.store` handler that makes this possible: it does not assume
 * `CreateNewUser::create()` returns a fresh `Authenticatable` to log in the
 * way Fortify's stock `RegisteredUserController` does.
 *
 * Amended 2026-09-02 (#143, ADR-0051 §3): the person picks their username at
 * registration under the same rules as the profile form; nothing is generated.
 */
#[TypeScript]
final class RegisterData extends Data
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        public readonly string $name,
        public readonly string $username,
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => self::nameRules(),
            'username' => self::usernameRules(),
            'email' => self::emailFormatRules(),
            'password' => self::passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return self::usernameMessages();
    }
}
