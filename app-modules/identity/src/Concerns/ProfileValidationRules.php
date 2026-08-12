<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Konventix\Identity\Models\User;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * Declared `static` (rather than the more conventional instance method)
     * so it can be reused both from `FormRequest::rules()` (called via
     * `$this->`, which PHP permits on a static method) and from a
     * `Data::rules()` static context (ADR-0009) — one source for both.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected static function profileRules(?string $userId = null): array
    {
        return [
            'name' => static::nameRules(),
            'email' => static::emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected static function emailRules(?string $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
