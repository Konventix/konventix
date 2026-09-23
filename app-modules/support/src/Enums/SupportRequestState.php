<?php

declare(strict_types=1);

namespace Konventix\Support\Enums;

/**
 * Where a report stands (design §4.3). Five states, one direction, with one
 * way back: `received` on arrival, `seen` the moment a console opens it,
 * then a human decision, and a reopen for the case where "resolved" turned
 * out to be wrong.
 *
 * The transition table lives here rather than in the Action so the console,
 * the tests and the Action all read the same one; `TransitionSupportRequest`
 * is what enforces it.
 *
 * `received → seen` is the only automatic move, and it is why
 * `support_requests_unseen_oldest_age_seconds` is a meaningful gauge: a
 * report nobody has opened is a report nobody has read.
 */
enum SupportRequestState: string
{
    case Received = 'received';
    case Seen = 'seen';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';

    public function label(): string
    {
        return match ($this) {
            self::Received => __('Received'),
            self::Seen => __('Seen'),
            self::InProgress => __('In progress'),
            self::Resolved => __('Resolved'),
            self::WontFix => __("Won't fix"),
        };
    }

    /**
     * A terminal state closes the request and starts the retention clock
     * (`closed_at`, D-SUP-11). A reporter may not write into a terminal
     * thread.
     */
    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::WontFix;
    }

    /**
     * Where this state may go next (design §4.3).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::Seen],
            self::Seen, self::InProgress => [self::InProgress, self::Resolved, self::WontFix],
            // Reopening is the one way back, and the only way an operator
            // can correct a wrong call without filing a second report.
            self::Resolved, self::WontFix => [self::InProgress],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * The states that mean somebody still owes an answer — what
     * `support_requests_open{brand}` counts.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Received, self::Seen, self::InProgress];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
