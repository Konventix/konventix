<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;
use Konventix\Identity\Models\User;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * Declared `static` (rather than the more conventional instance method)
     * so it can be reused both from `FormRequest::rules()` (called via
     * `$this->`, which PHP permits on a static method) and from a
     * `Data::rules()` static context (ADR-0009) — one source for both.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected static function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected static function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }

    /**
     * The current-password rules for a particular account. A Steam-only
     * account (`users.password` is nullable since slice 15a) has nothing to
     * confirm, so demanding `current_password` there is a form nobody can
     * submit; the field must then stay out of the request altogether —
     * `missing`, not `nullable`, so a stray value is never quietly ignored.
     * Every account that does hold a password keeps the full check.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected static function currentPasswordRulesFor(?User $user): array
    {
        if ($user !== null && ! $user->hasPassword()) {
            return ['missing'];
        }

        return self::currentPasswordRules();
    }
}
