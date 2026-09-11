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
 *
 * `acknowledgedClosedAccountHistoryStaysBehind` is the operator's tick on
 * the "Merge accounts" card's own checkbox — format only here, `boolean`,
 * same as `FooterLinkData::$opensInNewTab`. Whether it is actually *true*
 * is a business rule, not a shape rule, and is enforced where the other
 * merge refusals live: `Actions\MergeUserAccounts::handle()`.
 */
#[TypeScript]
final class MergeUserAccountData extends Data
{
    use ProfileValidationRules;

    public function __construct(
        public readonly string $email,
        public readonly bool $acknowledgedClosedAccountHistoryStaysBehind = false,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'email' => self::emailFormatRules(),
            'acknowledgedClosedAccountHistoryStaysBehind' => ['boolean'],
        ];
    }
}
