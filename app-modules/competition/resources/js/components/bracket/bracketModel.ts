/**
 * The one reading of a drawn stage that both of its renderings share
 * (design §10, D-CMP-22): the canvas and the table are built from the same
 * `bracketSections()` of the same `BracketData`, so they cannot disagree
 * about who plays whom. `bracketModel.test.ts` pins that.
 *
 * A **section** is one bracket of the stage — the whole of a one-bracket
 * format (`bracket` null), or double elimination's winners, losers and
 * grand final, or one group of a group stage. Sections keep the server's
 * play order; rounds run in ascending order and matches by position.
 *
 * The labels are catalog lookups (ADR-0068), made when a label is asked
 * for — never at import time, because the catalog is loaded after the
 * modules are imported.
 */
import { trans } from 'laravel-vue-i18n';

type BracketData = Konventix.Competition.Data.BracketData;
type BracketMatch = Konventix.Competition.Data.BracketMatchData;

export type BracketRound = {
    round: number;
    label: string;
    matches: BracketMatch[];
};

export type BracketSection = {
    key: string;
    bracket: string | null;
    label: string | null;
    rounds: BracketRound[];
};

function sectionLabels(): Record<string, string> {
    return {
        winners: trans('Winners bracket'),
        losers: trans('Losers bracket'),
        grand_final: trans('Grand final'),
    };
}

/**
 * The heading of a section: null for a one-bracket format, which needs
 * none; "Group A" for a group stage's `group-a`.
 */
export function sectionLabel(bracket: string | null): string | null {
    if (bracket === null) {
        return null;
    }

    return (
        sectionLabels()[bracket] ??
        bracket.replace(/^group-(.+)$/, (_, name: string) => {
            return trans('Group :name', { name: name.toUpperCase() });
        })
    );
}

/**
 * A round's name. Only a single-elimination bracket has rounds with names of
 * their own — counted back from its last round: Final, Semi-final,
 * Quarter-final. Everything else, and every earlier round, is "Round n".
 */
export function roundLabel(
    format: string,
    bracket: string | null,
    round: number,
    lastRound: number,
): string {
    if (format === 'single_elimination' && bracket === null) {
        const fromTheEnd = lastRound - round;
        const named = [
            trans('Final round'),
            trans('Semi-final'),
            trans('Quarter-final'),
        ][fromTheEnd];

        if (named !== undefined) {
            return named;
        }
    }

    return trans('Round :round', { round: String(round) });
}

export function bracketSections(bracket: BracketData): BracketSection[] {
    const sections = new Map<string, Map<number, BracketMatch[]>>();
    const brackets = new Map<string, string | null>();

    for (const match of bracket.matches) {
        const key = match.bracket ?? '';

        if (!sections.has(key)) {
            sections.set(key, new Map());
            brackets.set(key, match.bracket);
        }

        const rounds = sections.get(key)!;

        if (!rounds.has(match.round)) {
            rounds.set(match.round, []);
        }

        rounds.get(match.round)!.push(match);
    }

    return [...sections.entries()].map(([key, rounds]) => {
        const numbers = [...rounds.keys()].sort((a, b) => a - b);
        const last = numbers[numbers.length - 1] ?? 0;
        const sectionBracket = brackets.get(key) ?? null;

        return {
            key,
            bracket: sectionBracket,
            label: sectionLabel(sectionBracket),
            rounds: numbers.map((round) => ({
                round,
                label: roundLabel(bracket.format, sectionBracket, round, last),
                matches: [...rounds.get(round)!].sort(
                    (a, b) => a.position - b.position,
                ),
            })),
        };
    });
}

/*
 * ---------------------------------------------------------------------------
 * Canvas layout — ported from LanBrackets' `useBracketCanvas.ts`
 * (`layoutSingleElimination` / `layoutDoubleElimination`), generalised from
 * its two hard-coded shapes to "sections stacked, grand final to the right".
 * ---------------------------------------------------------------------------
 */

export const MATCH_WIDTH = 220;
export const MATCH_HEIGHT = 60;
export const ROUND_GAP_X = 60;
export const MATCH_GAP_Y = 20;
export const SECTION_GAP_Y = 60;
/** Room above a named section for its label. */
export const LABEL_SPACE = 24;

export type PlacedMatch = {
    match: BracketMatch;
    section: BracketSection;
    column: number;
    x: number;
    y: number;
};

/**
 * Where every match sits on the canvas.
 *
 * Within a section each round is a column, and its matches share the height
 * of the section's widest round evenly, each centred in its share — so a
 * match lands between the two it is fed from, as LanBrackets drew it.
 * Sections stack top to bottom in play order; a grand final goes to the right
 * of the widest one, centred on the whole height, its rounds (a reset) one
 * column each.
 */
export function layoutBracket(sections: BracketSection[]): PlacedMatch[] {
    const placed: PlacedMatch[] = [];
    const stacked = sections.filter((s) => s.bracket !== 'grand_final');
    const finals = sections.filter((s) => s.bracket === 'grand_final');

    let top = 0;
    let columns = 0;

    for (const section of stacked) {
        top += section.label === null ? 0 : LABEL_SPACE;

        const widest = Math.max(
            1,
            ...section.rounds.map((r) => r.matches.length),
        );
        const height = widest * (MATCH_HEIGHT + MATCH_GAP_Y) - MATCH_GAP_Y;

        section.rounds.forEach((round, column) => {
            const spacing = height / round.matches.length;

            round.matches.forEach((match, index) => {
                placed.push({
                    match,
                    section,
                    column,
                    x: column * (MATCH_WIDTH + ROUND_GAP_X),
                    y: top + index * spacing + (spacing - MATCH_HEIGHT) / 2,
                });
            });
        });

        columns = Math.max(columns, section.rounds.length);
        top += height + SECTION_GAP_Y;
    }

    const bottom = Math.max(0, top - SECTION_GAP_Y);

    for (const section of finals) {
        section.rounds.forEach((round, offset) => {
            round.matches.forEach((match, index) => {
                placed.push({
                    match,
                    section,
                    column: columns + offset,
                    x: (columns + offset) * (MATCH_WIDTH + ROUND_GAP_X),
                    y:
                        bottom / 2 -
                        MATCH_HEIGHT / 2 +
                        index * (MATCH_HEIGHT + MATCH_GAP_Y),
                });
            });
        });
    }

    return placed;
}

/** The match under a point in layout coordinates, for a click on the canvas. */
export function matchAt(
    placed: PlacedMatch[],
    x: number,
    y: number,
): PlacedMatch | null {
    return (
        placed.find(
            (item) =>
                x >= item.x &&
                x <= item.x + MATCH_WIDTH &&
                y >= item.y &&
                y <= item.y + MATCH_HEIGHT,
        ) ?? null
    );
}

/** How a side is named when nobody fills it yet. */
export function sideName(
    side: Konventix.Competition.Data.BracketSideData | undefined,
): string {
    if (side === undefined || side.teamName === null) {
        return side?.awaiting ? trans('Open') : trans('Bye');
    }

    return side.teamName;
}
