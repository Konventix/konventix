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
 * Deviation from ADR-0016: the reused `emailRules()` includes
 * `Rule::unique(User::class)`, so a duplicate address surfaces as an ordinary
 * validation error — the ADR requires this path be indistinguishable from
 * success ("check your inbox", never a validation error) because email is
 * unique operator-wide. Making that hold end-to-end means `CreateNewUser`
 * cannot return a real `Authenticatable` for `RegisteredUserController` to
 * auto-login on a duplicate, which means replacing Fortify's stock
 * registration controller/response wiring — out of scope for this PR. Flagged
 * rather than worked around silently; see TODO.md.
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
            'email' => self::emailRules(),
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
