/**
 * How the desk names a match and a round: "Winners · Round 2 · Match 1".
 * The bracket is only named where a format has more than one (double
 * elimination, a group stage); `null` is every one-bracket format.
 */
const BRACKETS: Record<string, string> = {
    winners: 'Winners',
    losers: 'Losers',
    grand_final: 'Grand final',
};

export function bracketLabel(bracket: string | null): string | null {
    if (bracket === null) {
        return null;
    }

    return BRACKETS[bracket] ?? bracket.replace(/^group-/, 'Group ');
}

export function roundLabel(bracket: string | null, round: number): string {
    return [bracketLabel(bracket), `Round ${round}`]
        .filter((part) => part !== null)
        .join(' · ');
}

export function matchLabel(match: {
    bracket: string | null;
    round: number;
    position: number;
}): string {
    return `${roundLabel(match.bracket, match.round)} · Match ${match.position}`;
}
