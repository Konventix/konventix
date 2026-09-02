<?php

declare(strict_types=1);

namespace Konventix\Identity\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Unique;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;

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
     * The handle rules every form that takes a username shares (ADR-0051 §3,
     * amended 2026-09-02: registration asks for it too): the format, the
     * reserved list, and operator-wide uniqueness folded to lower case.
     * `$userId` exempts the account editing its own handle.
     *
     * @return array<int, NotIn|ValidationRule|Closure|string>
     */
    protected static function usernameRules(?string $userId = null): array
    {
        return [
            'required', 'string', 'regex:'.UsernameGenerator::FORMAT,
            Rule::notIn(UsernameGenerator::RESERVED),
            function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                $taken = User::query()
                    ->whereRaw('lower(username) = ?', [mb_strtolower((string) $value)])
                    ->when($userId !== null, fn ($query) => $query->whereKeyNot($userId))
                    ->exists();

                if ($taken) {
                    $fail(__('This username is already taken.'));
                }
            },
        ];
    }

    /**
     * Messages that say what shape a handle must have, instead of Laravel's
     * "format is invalid".
     *
     * @return array<string, string>
     */
    protected static function usernameMessages(): array
    {
        return [
            'username.regex' => __('Use 3-32 lowercase letters, digits and hyphens, starting and ending with a letter or digit.'),
            'username.not_in' => __('This username is reserved.'),
        ];
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
