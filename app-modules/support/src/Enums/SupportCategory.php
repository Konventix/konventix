<?php

declare(strict_types=1);

namespace Konventix\Support\Enums;

/**
 * What the reporter says is wrong, in the order the dialog offers them
 * (design §4.3, from #88). A report carries at least one and may carry
 * several — a broken checkout page is usually a purchase problem *and* a
 * display problem, and forcing a single choice would lose whichever the
 * person did not pick.
 *
 * These five are a **bounded set on purpose**: they are a metric label
 * (`support_requests_total{brand, category}`) and the selector of the one
 * alert Support ships. An operator-editable category list would be
 * unbounded cardinality in the registry, which `.ai/rules/observability.md`
 * forbids, so growing this list is a code change and a deliberate one.
 *
 * `PurchaseAffected` is the one with an operational meaning beyond triage:
 * D-SUP-06 routes it to the on-call channel through
 * `KonventixSupportPurchaseAffected`, never through Commerce's topic key.
 */
enum SupportCategory: string
{
    case PurchaseAffected = 'purchase_affected';
    case Visibility = 'visibility';
    case Function = 'function';
    case Display = 'display';
    case Suggestion = 'suggestion';

    /**
     * The English label the dialog and the console inbox print. UI copy is
     * English like the rest of the application (D-SUP-09).
     */
    public function label(): string
    {
        return match ($this) {
            self::PurchaseAffected => 'Purchase affected',
            self::Visibility => 'Something is not visible',
            self::Function => 'Something does not work',
            self::Display => 'Something looks wrong',
            self::Suggestion => 'Suggestion',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
