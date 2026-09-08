<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;

/**
 * The operator-facing escalation payload: which request, how long is left,
 * and how many modules have still not answered — the three facts that decide
 * whether anyone needs to do something today.
 *
 * `daysOverdue` is `daysLeft` seen from the other side, and it exists here
 * rather than in the template because of GitLab #9: an override is raw Blade
 * and `TemplateOverrideGuard` allows a payload field, an `@if` on one and
 * nothing else. The shipped default used a ternary with `abs()` in it, so an
 * operator who opened the editor could not save the very text the editor had
 * seeded — the arithmetic belongs to the payload, and the template only
 * chooses a sentence.
 *
 * Zero when the deadline has not passed: `abs($daysLeft)` on a positive
 * `daysLeft` would read as "overdue by five days" on a request with five days
 * left, and the only correct guard against that is that the branch printing
 * it never runs.
 */
final class DeadlineWarningMailData extends Data
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $requestType,
        public readonly string $deadline,
        public readonly int $daysLeft,
        public readonly int $daysOverdue,
        public readonly int $openModules,
    ) {}

    /**
     * `of*`, never `from*`: laravel-data routes casts and `from()` through
     * any public static `from*` method by best-match signature
     * (.ai/rules/data.md).
     */
    public static function ofDeadline(
        string $requestId,
        string $requestType,
        string $deadline,
        int $daysLeft,
        int $openModules,
    ): self {
        return new self(
            requestId: $requestId,
            requestType: $requestType,
            deadline: $deadline,
            daysLeft: $daysLeft,
            daysOverdue: max(0, -$daysLeft),
            openModules: $openModules,
        );
    }
}
