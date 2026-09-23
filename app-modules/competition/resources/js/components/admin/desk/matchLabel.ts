import { trans } from 'laravel-vue-i18n';

/**
 * How the desk names a match and a round: "Winners · Round 2 · Match 1".
 * The bracket is only named where a format has more than one (double
 * elimination, a group stage); `null` is every one-bracket format.
 *
 * Looked up per call, never at import time: the catalog is loaded after the
 * modules are imported (ADR-0068).
 */
function brackets(): Record<string, string> {
    return {
        winners: trans('Winners'),
        losers: trans('Losers'),
        grand_final: trans('Grand final'),
    };
}

export function bracketLabel(bracket: string | null): string | null {
    if (bracket === null) {
        return null;
    }

    const group = /^group-(.+)$/.exec(bracket);

    return (
        brackets()[bracket] ??
        (group === null ? bracket : trans('Group :name', { name: group[1] }))
    );
}

export function roundLabel(bracket: string | null, round: number): string {
    return [
        bracketLabel(bracket),
        trans('Round :round', { round: String(round) }),
    ]
        .filter((part) => part !== null)
        .join(' · ');
}

export function matchLabel(match: {
    bracket: string | null;
    round: number;
    position: number;
}): string {
    return `${roundLabel(match.bracket, match.round)} · ${trans('Match :position', { position: String(match.position) })}`;
}
