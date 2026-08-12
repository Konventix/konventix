<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\ProfileValidationRules;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single source of validation rules, Inertia props shape, and generated
 * TypeScript type for `PATCH /settings/profile` (ADR-0009). Replaces
 * `ProfileUpdateRequest`: the container resolves and validates this directly
 * when it is type-hinted on `ProfileController::update()`, the same way
 * `RegistrationController` already consumes `SubmitRegistrationData`.
 */
#[TypeScript]
final class ProfileUpdateData extends Data
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
        $userId = auth()->id();

        return self::profileRules($userId !== null ? (string) $userId : null);
    }
}
