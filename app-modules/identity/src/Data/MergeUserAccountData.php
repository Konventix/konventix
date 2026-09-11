<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\ProfileValidationRules;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator-console "merge accounts" form (ADR-0009, #81): the other
 * account, named by its address. Format only — `emailFormatRules()`, not
 * `emailRules()` — because "does an account exist at this address" is not a
 * question this Data class answers; `Actions\MergeUserAccounts` answers it,
 * and turns "no" into a validation error of its own, which is fine here in a
 * way it is not on the public forms `ProfileValidationRules::emailFormatRules()`'s
 * own docblock describes: this endpoint sits behind `ManageUsers`, and an
 * operator who can already search the user list by email
 * (`UserAdminController::index()`) learns nothing from this error that
 * search did not already tell them.
 */
#[TypeScript]
final class MergeUserAccountData extends Data
{
    use ProfileValidationRules;

    public function __construct(
        public readonly string $email,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'email' => self::emailFormatRules(),
        ];
    }
}
