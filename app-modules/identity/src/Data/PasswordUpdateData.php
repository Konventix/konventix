<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\PasswordValidationRules;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single source of validation rules, Inertia props shape, and generated
 * TypeScript type for `PUT /settings/password` (ADR-0009). Field names stay
 * snake_case (`current_password`) rather than the project's usual camelCase
 * Data properties: this is Laravel/Fortify's own convention (the
 * `current_password` validation rule, the starter-kit form fields), and there
 * is no name-mapping strategy configured (`config/data.php`) to translate
 * between the two, so the property name must match the posted field exactly.
 */
#[TypeScript]
final class PasswordUpdateData extends Data
{
    use PasswordValidationRules;

    public function __construct(
        public readonly string $current_password,
        public readonly string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'current_password' => self::currentPasswordRules(),
            'password' => self::passwordRules(),
        ];
    }
}
