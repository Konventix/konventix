<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * What kind of thing is being contested (ADR-0024).
 *
 * The ADR's central move: "a video game is one kind of discipline; a pinball
 * table, a quiz format and a judged contest are others. GameOps handles only
 * the disciplines that are video games." Duration hints, eligibility and
 * scoring hang off `Discipline` uniformly, so there is no null-game case to
 * fall back from and no null-safe chain in every consumer.
 *
 * Only `VideoGame` ever carries a GameOps reference. The rest are entirely
 * self-contained, which is why Competition stays useful with GameOps switched
 * off — or, as now, not yet built.
 */
enum DisciplineKind: string
{
    case VideoGame = 'video_game';
    case Tabletop = 'tabletop';
    case Quiz = 'quiz';
    case Judged = 'judged';

    /**
     * Whether this kind can be automated by GameOps. Only video games have a
     * server to provision.
     */
    public function isAutomatable(): bool
    {
        return $this === self::VideoGame;
    }
}
