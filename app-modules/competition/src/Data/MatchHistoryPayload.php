<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\MatchHistoryKind;
use Spatie\LaravelData\Data;

/**
 * What a match-history row carries, typed per kind (design §8.6, ADR-0009).
 *
 * One class rather than thirteen, because the fields overlap heavily and the
 * timeline is rendered by one component: every row shows a kind, most rows
 * name the side they are about, and about half carry a note or a score line.
 * What makes it typed rather than a bag is this table — a field is only ever
 * written for the kinds named beside it, and every writer in the module goes
 * through the named constructors below:
 *
 * | Field | Written for |
 * |---|---|
 * | `entrantId` | `reported`, `confirmed`, `disputed` — the side that acted |
 * | `scores` | `reported`, `recorded`, `corrected`, `resolved` — `position => score` |
 * | `winnerEntrantId` | `recorded`, `corrected`, `resolved` |
 * | `previousWinnerEntrantId` | `corrected` |
 * | `note` | `disputed` (the reason), `resolved` and `voided_by_correction` (the organizer's mandatory note), `cancelled` |
 * | `proofMediaId` | `reported`, when the competition takes proofs (§8.7) |
 * | `scheduledAt` | `scheduled`, `rescheduled` — ISO 8601 |
 * | `previousScheduledAt` | `rescheduled` — ISO 8601 |
 * | `sourceMatchId` | `voided_by_correction` — the corrected match upstream |
 *
 * Nothing here is a ULID the reader resolves themself: a page turns
 * `entrantId` into a team name and `actor_user_id` into a display name from
 * `ParticipantProfile`, never from Identity (§10).
 *
 * The static helper is named `of*` and not `from*` on purpose: laravel-data
 * routes any public static `from*` through its magic-creation machinery and
 * a helper taking an array would silently hijack `from()`
 * (.ai/rules/data.md).
 */
final class MatchHistoryPayload extends Data
{
    /**
     * @param  array<int, int|null>|null  $scores  slot position => score
     */
    public function __construct(
        public readonly ?string $entrantId = null,
        public readonly ?array $scores = null,
        public readonly ?string $winnerEntrantId = null,
        public readonly ?string $previousWinnerEntrantId = null,
        public readonly ?string $note = null,
        public readonly ?string $proofMediaId = null,
        public readonly ?string $scheduledAt = null,
        public readonly ?string $previousScheduledAt = null,
        public readonly ?string $sourceMatchId = null,
    ) {}

    /**
     * Rebuild the object from the stored json.
     *
     * Missing keys are the normal case, not an error: a row written for one
     * kind carries only that kind's fields, and a row written before a field
     * existed carries none of it.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function ofStoredPayload(?array $payload): self
    {
        $payload ??= [];

        /** @var array<int, int|null>|null $scores */
        $scores = is_array($payload['scores'] ?? null) ? $payload['scores'] : null;

        return new self(
            entrantId: self::stringOrNull($payload['entrantId'] ?? null),
            scores: $scores,
            winnerEntrantId: self::stringOrNull($payload['winnerEntrantId'] ?? null),
            previousWinnerEntrantId: self::stringOrNull($payload['previousWinnerEntrantId'] ?? null),
            note: self::stringOrNull($payload['note'] ?? null),
            proofMediaId: self::stringOrNull($payload['proofMediaId'] ?? null),
            scheduledAt: self::stringOrNull($payload['scheduledAt'] ?? null),
            previousScheduledAt: self::stringOrNull($payload['previousScheduledAt'] ?? null),
            sourceMatchId: self::stringOrNull($payload['sourceMatchId'] ?? null),
        );
    }

    /**
     * The row as it is stored: the fields this kind actually filled, and no
     * nulls. A timeline row for a reschedule should not carry nine keys of
     * which seven are empty — and a reader that sees a key can trust that the
     * writer meant it.
     *
     * @return array<string, mixed>
     */
    public function forStorage(): array
    {
        return array_filter([
            'entrantId' => $this->entrantId,
            'scores' => $this->scores,
            'winnerEntrantId' => $this->winnerEntrantId,
            'previousWinnerEntrantId' => $this->previousWinnerEntrantId,
            'note' => $this->note,
            'proofMediaId' => $this->proofMediaId,
            'scheduledAt' => $this->scheduledAt,
            'previousScheduledAt' => $this->previousScheduledAt,
            'sourceMatchId' => $this->sourceMatchId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Whether this payload carries everything the kind owes a reader.
     *
     * Only the three kinds with a *mandatory* field have one: §8.4's
     * resolution note and §8.5's void note are the whole point of those rows,
     * and a dispute without a reason tells the desk nothing. Everything else
     * is optional by design.
     */
    public function satisfies(MatchHistoryKind $kind): bool
    {
        return match ($kind) {
            MatchHistoryKind::Disputed,
            MatchHistoryKind::Resolved,
            MatchHistoryKind::VoidedByCorrection => $this->note !== null && trim($this->note) !== '',
            default => true,
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
