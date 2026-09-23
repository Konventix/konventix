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
     * slice because the desk action it gates exists now.
     */
    case CheckInEntrants = 'competition.check-in-entrants';

    /**
     * The desk's scheduling and drawing (design §9, D-CMP-21, D-CMP-23):
     * `scheduled_at` per match and per round, and the seed order, generate
     * and reset that decide who plays whom. Added by §9's slice for the same
     * reason as `CheckInEntrants` — the desk actions it gates exist now.
     */
    case ManageSchedule = 'competition.manage-schedule';

    case RecordResults = 'competition.record-results';
    case CorrectResults = 'competition.correct-results';

    /**
     * Seeing how to reach the people on a roster (design §9, §11, D-CMP-23).
     *
     * Declared now so the default roles §11 names are complete and stable —
     * a role an operator has already edited is never rewritten, so a case
     * added later would reach no existing brand's Tournament Admin. It gates
     * nothing yet: Competition holds no contact data. An e-mail address is
     * Identity's, which this module may not read (ADR-0004), and neither
     * `ParticipantProfile` nor any event it is projected from carries one.
     * The contact view on the entrants screen arrives with a read model that
     * does (design §14, "`ViewEntrantContacts` is declared and gates
     * nothing").
     */
    case ViewEntrantContacts = 'competition.view-entrant-contacts';
}
