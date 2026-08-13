<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Competition's capability catalogue (ADR-0013).
 *
 * `CorrectResults` is deliberately separate from `RecordResults`: D-CMP-11
 * settles that handler results record directly and advance the bracket, while
 * the desk holds a *correction* capability. Merging them would give every
 * automated reporter the power to rewrite history.
 */
enum Permission: string
{
    case ViewCompetitions = 'competition.view-competitions';
    case ManageDisciplines = 'competition.manage-disciplines';
    case ManageCompetitions = 'competition.manage-competitions';
    case ManageEntrants = 'competition.manage-entrants';
    case RecordResults = 'competition.record-results';
    case CorrectResults = 'competition.correct-results';
}
