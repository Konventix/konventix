<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

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
}
