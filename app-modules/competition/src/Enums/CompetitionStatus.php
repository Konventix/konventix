<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Where a competition stands in its lifecycle (D-CMP-15, design §4).
 *
 * The column replaces the implicit `published_at`-only state slice 10 shipped;
 * `published_at` stays as the timestamp of the transition. `SignupClosed` sits
 * between `Published` and `Running` on purpose: an organizer closes signups,
 * resolves incomplete rosters (§5.4) and seeds the bracket long before
 * anything is played, and a two-state model forces those three into one click.
 *
 * The transition table lives here rather than in the Actions, because every
 * lifecycle Action consults the same one — the inventory's single "port as-is"
 * verdict for LanCore's competition was exactly this enum.
 */
enum CompetitionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case SignupClosed = 'signup_closed';
    case Running = 'running';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /**
     * The states this one may legally move to (design §4).
     *
     * `Published → Draft` is the unpublish arrow and `SignupClosed →
     * Published` the reopen arrow; both are in the diagram. `Cancelled` is
     * reachable from everything but `Finished` — a competition that was
     * played to its end is history, not something to call off. Neither
     * terminal state leads anywhere.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Cancelled],
            self::Published => [self::Draft, self::SignupClosed, self::Cancelled],
            self::SignupClosed => [self::Published, self::Running, self::Cancelled],
            self::Running => [self::Finished, self::Cancelled],
            self::Finished, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the competition has a public page at all (design §4): a draft
     * is being configured and is nobody's business yet; every other state is
     * visible, including `Cancelled`, which is visible *and marked*.
     */
    public function isPubliclyVisible(): bool
    {
        return $this !== self::Draft;
    }

    /** Nothing moves out of these two. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether signup may be open in this state. In `Published` it still
     * depends on the window (`Competition::signupIsOpen()`); everywhere else
     * the answer is no regardless of the dates.
     */
    public function mayAcceptSignups(): bool
    {
        return $this === self::Published;
    }
}
