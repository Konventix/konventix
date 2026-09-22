<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * What a row of `competition_match_history` records (design §8.6).
 *
 * "Die nachvollziehbare Historie pro Match" #116 asks for, delivered without
 * the chat: every step a match went through, who did it, and what it said —
 * shown to both sides and to the desk on the match detail page.
 *
 * The set is closed and complete as §8.6 lists it, including three kinds
 * nothing in this release writes:
 *
 * - `CheckedIn` — a per-match check-in. Check-in in this slice is per
 *   competition (§6), so the kind is reserved for the desk slice that gives a
 *   match its own gate.
 * - `SessionLive` / `SessionFailed` — **GameOps' in 0.10** (#115). They are
 *   shipped here rather than added later so the match timeline a page renders
 *   does not change shape when GameOps starts writing into it, and so the
 *   two modules cannot disagree about what the kinds are called. Competition
 *   never writes them.
 *
 * A kind is a plain string column rather than a database enum: a new kind is
 * a case here, not a migration.
 */
enum MatchHistoryKind: string
{
    /** The match was given a time for the first time. */
    case Scheduled = 'scheduled';

    /** An existing `scheduled_at` moved. */
    case Rescheduled = 'rescheduled';

    /** Reserved: a per-match check-in. Nothing writes this in 0.9. */
    case CheckedIn = 'checked_in';

    /** One side submitted a result (§8.2). */
    case Reported = 'reported';

    /** The other side agreed with it (§8.2). */
    case Confirmed = 'confirmed';

    /** The two sides disagree, or a side contests (§8.4). */
    case Disputed = 'disputed';

    /** The desk decided a dispute, with its mandatory note (§8.4). */
    case Resolved = 'resolved';

    /** A result was written to the match, whoever agreed it. */
    case Recorded = 'recorded';

    /** The desk rewrote a recorded result (§8.5). */
    case Corrected = 'corrected';

    /** An upstream correction took this match's result away (§8.5). */
    case VoidedByCorrection = 'voided_by_correction';

    /** The match will not be played. */
    case Cancelled = 'cancelled';

    /** Reserved for GameOps in 0.10 (#115). Competition never writes it. */
    case SessionLive = 'session_live';

    /** Reserved for GameOps in 0.10 (#115). Competition never writes it. */
    case SessionFailed = 'session_failed';

    /**
     * Whether this module writes the kind at all.
     *
     * Three of the thirteen are shipped unwritten — two belong to GameOps and
     * one to a per-match check-in that does not exist yet — and a test asserts
     * that this release writes none of them, so "reserved" stays a fact rather
     * than a comment.
     */
    public function isWrittenByCompetition(): bool
    {
        return ! in_array($this, [self::CheckedIn, self::SessionLive, self::SessionFailed], true);
    }
}
