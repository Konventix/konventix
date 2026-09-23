/**
 * A leaderboard value as the public standings print it (design §7.2).
 *
 * An entrant with no verified score has no value (final review, part 1, I6),
 * and gets a dash with **no unit** behind it: "— s" reads like a time of
 * dash seconds (fix wave B, item 10).
 */
export function formatScore(value: number | null, unit: string): string {
    if (value === null) {
        return '—';
    }

    return `${value} ${unit}`;
}
