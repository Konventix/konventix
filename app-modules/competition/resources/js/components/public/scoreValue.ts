/**
 * A leaderboard value as the public standings print it (design §7.2).
 *
 * - An entrant with no verified score has no value (final review, part 1,
 *   I6), and gets a dash with **no unit** behind it: "— s" reads like a time
 *   of dash seconds (fix wave B, item 10).
 * - The number is German (ADR-0001; final review, part 3, M6): a decimal
 *   comma, and exactly the stage's `decimals` places, so a lap time of 61.5
 *   with two decimals reads "61,50 s". Without a setting (an older payload)
 *   it prints what it has, up to the three places the column stores.
 */
export function formatScore(
    value: number | null,
    unit: string,
    decimals: number | null = null,
): string {
    if (value === null) {
        return '—';
    }

    const number = new Intl.NumberFormat(
        'de-DE',
        decimals === null
            ? { maximumFractionDigits: 3 }
            : {
                  minimumFractionDigits: decimals,
                  maximumFractionDigits: decimals,
              },
    ).format(value);

    return `${number} ${unit}`;
}
