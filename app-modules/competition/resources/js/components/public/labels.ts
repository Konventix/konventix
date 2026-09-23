/**
 * German names for the enum values the public and participant pages show
 * (ADR-0001). An unknown value falls through as itself rather than as
 * nothing, so a case added on the server is visible before it is named here.
 */
const STATUSES: Record<string, string> = {
    published: 'Anmeldung',
    signup_closed: 'Anmeldung geschlossen',
    running: 'Läuft',
    finished: 'Beendet',
    cancelled: 'Abgesagt',
};

const FORMATS: Record<string, string> = {
    single_elimination: 'K.-o.-System',
    double_elimination: 'Doppel-K.-o.',
    round_robin: 'Jeder gegen jeden',
    group_stage: 'Gruppenphase',
    swiss: 'Schweizer System',
    leaderboard: 'Bestenliste',
};

const MATCH_STATES: Record<string, string> = {
    pending: 'Offen',
    scheduled: 'Angesetzt',
    live: 'Läuft',
    finished: 'Beendet',
    cancelled: 'Abgesagt',
};

const HISTORY_KINDS: Record<string, string> = {
    scheduled: 'Angesetzt',
    rescheduled: 'Verlegt',
    checked_in: 'Eingecheckt',
    reported: 'Ergebnis gemeldet',
    confirmed: 'Ergebnis bestätigt',
    disputed: 'Streitfall',
    resolved: 'Streitfall entschieden',
    recorded: 'Ergebnis eingetragen',
    corrected: 'Ergebnis korrigiert',
    voided_by_correction: 'Durch Korrektur aufgehoben',
    cancelled: 'Abgesagt',
    session_live: 'Server gestartet',
    session_failed: 'Server-Fehler',
};

export function statusLabel(status: string): string {
    return STATUSES[status] ?? status;
}

export function formatLabel(format: string | null): string {
    return format === null ? '—' : (FORMATS[format] ?? format);
}

export function matchStateLabel(state: string): string {
    return MATCH_STATES[state] ?? state;
}

export function historyLabel(kind: string): string {
    return HISTORY_KINDS[kind] ?? kind;
}

/** "1v1", "5v5" — how a LAN names a team size. */
export function teamSizeLabel(size: number): string {
    return `${size}v${size}`;
}
