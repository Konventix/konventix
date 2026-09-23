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

    /**
     * The desk's check-in list (design §6, §9, D-CMP-23). Added here by §6's
     * slice because the desk action it gates exists now; §11's full
     * capability pass — `ManageSchedule`, `ViewEntrantContacts` and the
     * `Tournament Desk` role that grants a subset of these — is its own task.
     */
    case CheckInEntrants = 'competition.check-in-entrants';

    /**
     * The desk's scheduling and drawing (design §9, D-CMP-21, D-CMP-23):
     * `scheduled_at` per match and per round, and the seed order, generate
     * and reset that decide who plays whom. Added by §9's slice for the same
     * reason as `CheckInEntrants` — the desk actions it gates exist now;
     * `ViewEntrantContacts` and the `Tournament Desk` role stay §11's pass.
     */
    case ManageSchedule = 'competition.manage-schedule';

    case RecordResults = 'competition.record-results';
    case CorrectResults = 'competition.correct-results';
}
