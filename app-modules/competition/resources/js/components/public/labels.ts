import { trans } from 'laravel-vue-i18n';

/**
 * The words the public and participant pages print for enum values
 * (ADR-0068). An unknown value falls through as itself rather than as
 * nothing, so a case added on the server is visible before it is named here.
 *
 * Each label is looked up when it is asked for, never at import time: the
 * catalog is loaded after the modules are imported.
 */
function statuses(): Record<string, string> {
    return {
        published: trans('Signup open'),
        signup_closed: trans('Signup closed'),
        running: trans('Running'),
        finished: trans('Finished'),
        cancelled: trans('Called off'),
    };
}

function formats(): Record<string, string> {
    return {
        single_elimination: trans('Single elimination'),
        double_elimination: trans('Double elimination'),
        round_robin: trans('Round robin'),
        group_stage: trans('Group stage'),
        swiss: trans('Swiss'),
        leaderboard: trans('Leaderboard'),
    };
}

function matchStates(): Record<string, string> {
    return {
        pending: trans('Open'),
        scheduled: trans('Scheduled'),
        live: trans('Live'),
        finished: trans('Finished'),
        cancelled: trans('Called off'),
    };
}

function historyKinds(): Record<string, string> {
    return {
        scheduled: trans('Scheduled'),
        rescheduled: trans('Rescheduled'),
        checked_in: trans('Checked in'),
        reported: trans('Result reported'),
        confirmed: trans('Result confirmed'),
        disputed: trans('In dispute'),
        resolved: trans('Dispute resolved'),
        recorded: trans('Result recorded'),
        corrected: trans('Result corrected'),
        voided_by_correction: trans('Voided by a correction'),
        cancelled: trans('Called off'),
        session_live: trans('Server started'),
        session_failed: trans('Server error'),
    };
}

export function statusLabel(status: string): string {
    return statuses()[status] ?? status;
}

/**
 * A competition's state as the console names it — every state, including
 * the draft the public never sees.
 */
export function consoleStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        draft: trans('Draft'),
        published: trans('Published'),
        signup_closed: trans('Signup closed'),
        running: trans('Running'),
        finished: trans('Finished'),
        cancelled: trans('Called off'),
    };

    return labels[status] ?? status;
}

/** What a discipline is (`DisciplineKind`). */
export function disciplineKindLabel(kind: string): string {
    const labels: Record<string, string> = {
        video_game: trans('Video game'),
        tabletop: trans('Tabletop'),
        quiz: trans('Quiz'),
        judged: trans('Judged'),
    };

    return labels[kind] ?? kind;
}

/** A team's standing in a competition (`EntrantState`). */
export function entrantStateLabel(state: string): string {
    const labels: Record<string, string> = {
        incomplete: trans('Not complete yet'),
        registered: trans('Signed up'),
        checked_in: trans('Checked in'),
        withdrawn: trans('Withdrawn'),
        disqualified: trans('Disqualified'),
    };

    return labels[state] ?? state;
}

/** Why a team left the field (`WithdrawalReason`), for the console. */
export function withdrawalReasonLabel(reason: string): string {
    const labels: Record<string, string> = {
        incomplete_roster: trans('roster incomplete at signup close'),
        captain_withdrew: trans('withdrawn by the captain'),
        organizer_withdrew: trans('withdrawn by the organizers'),
        disqualified: trans('disqualified'),
    };

    return labels[reason] ?? reason;
}

/** Who checks a team in (`CheckInMode`). */
export function checkInModeLabel(mode: string): string {
    const labels: Record<string, string> = {
        none: trans('No check-in'),
        self_service: trans('Self-service'),
        desk: trans('At the desk'),
    };

    return labels[mode] ?? mode;
}

/** Who reports results (`ResultSubmissionMode`). */
export function resultSubmissionModeLabel(mode: string): string {
    const labels: Record<string, string> = {
        desk_only: trans('The desk only'),
        participants: trans('The participants'),
    };

    return labels[mode] ?? mode;
}

/** Whether a result carries a proof image (`ProofPolicy`). */
export function proofPolicyLabel(policy: string): string {
    const labels: Record<string, string> = {
        none: trans('No proof'),
        optional: trans('Optional'),
        required: trans('Required'),
    };

    return labels[policy] ?? policy;
}

export function formatLabel(format: string | null): string {
    return format === null ? '—' : (formats()[format] ?? format);
}

export function matchStateLabel(state: string): string {
    return matchStates()[state] ?? state;
}

export function historyLabel(kind: string): string {
    return historyKinds()[kind] ?? kind;
}

/** "1v1", "5v5" — how a LAN names a team size. */
export function teamSizeLabel(size: number): string {
    return `${size}v${size}`;
}
