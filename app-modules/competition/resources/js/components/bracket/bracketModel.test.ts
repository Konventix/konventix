import { describe, expect, it } from 'vitest';
import {
    bracketSections,
    layoutBracket,
    MATCH_GAP_Y,
    MATCH_HEIGHT,
    MATCH_WIDTH,
    ROUND_GAP_X,
    roundLabel,
    sectionLabel,
    sideName,
} from './bracketModel';

type BracketData = Konventix.Competition.Data.BracketData;
type BracketMatch = Konventix.Competition.Data.BracketMatchData;

function side(
    position: number,
    teamName: string | null,
    awaiting = false,
): Konventix.Competition.Data.BracketSideData {
    return {
        position,
        entrantId: teamName === null ? null : `e-${teamName}`,
        teamId: teamName === null ? null : `t-${teamName}`,
        teamName,
        score: null,
        winner: false,
        awaiting,
    };
}

function match(
    id: string,
    bracket: string | null,
    round: number,
    position: number,
    names: (string | null)[],
): BracketMatch {
    return {
        id,
        bracket,
        round,
        position,
        state: 'pending',
        scheduledAt: null,
        disputed: false,
        sides: names.map((name, index) => side(index + 1, name, name === null)),
    };
}

/**
 * Four teams, single elimination, seeded 1..4 as A..D. S(4) = [1, 4, 2, 3]:
 *   round 1, match 1: A v D
 *   round 1, match 2: B v C
 *   round 2, match 1: winner of 1/1 v winner of 1/2 — both still to come
 * The server sends matches in play order; this fixture sends round 2 first
 * and round 1's matches reversed, so the grouping has to put them right.
 */
const fourTeams: BracketData = {
    format: 'single_elimination',
    matches: [
        match('final', null, 2, 1, [null, null]),
        match('semi-2', null, 1, 2, ['B', 'C']),
        match('semi-1', null, 1, 1, ['A', 'D']),
    ],
    connections: [
        {
            sourceMatchId: 'semi-1',
            outcome: 'winner',
            targetMatchId: 'final',
            targetSlot: 1,
        },
        {
            sourceMatchId: 'semi-2',
            outcome: 'winner',
            targetMatchId: 'final',
            targetSlot: 2,
        },
    ],
};

/** Who plays whom, as a list of "X v Y" per match id. */
function pairing(m: BracketMatch): string {
    return m.sides.map((s) => sideName(s)).join(' v ');
}

describe('bracketSections', () => {
    it('groups one bracket into rounds in order, matches by position', () => {
        const [section, ...rest] = bracketSections(fourTeams);

        expect(rest).toEqual([]);
        expect(section.label).toBeNull();
        expect(section.rounds.map((r) => [r.round, r.label])).toEqual([
            [1, 'Semi-final'],
            [2, 'Final round'],
        ]);
        expect(section.rounds.map((r) => r.matches.map(pairing))).toEqual([
            ['A v D', 'B v C'],
            ['Open v Open'],
        ]);
    });

    it('keeps double elimination in play order with a heading per section', () => {
        const sections = bracketSections({
            format: 'double_elimination',
            matches: [
                match('w1', 'winners', 1, 1, ['A', 'B']),
                match('l1', 'losers', 1, 1, [null, null]),
                match('gf', 'grand_final', 1, 1, [null, null]),
            ],
            connections: [],
        });

        expect(sections.map((s) => s.label)).toEqual([
            'Winners bracket',
            'Losers bracket',
            'Grand final',
        ]);
        // Double elimination's rounds have no names of their own.
        expect(sections.map((s) => s.rounds[0].label)).toEqual([
            'Round 1',
            'Round 1',
            'Round 1',
        ]);
    });
});

describe('round and section labels', () => {
    it('names single elimination rounds from the end', () => {
        // 8 teams: 3 rounds — 1 Quarter-final, 2 Semi-final, 3 Final.
        expect(
            [1, 2, 3].map((r) => roundLabel('single_elimination', null, r, 3)),
        ).toEqual(['Quarter-final', 'Semi-final', 'Final round']);
        // 16 teams: 4 rounds — the first has no name of its own.
        expect(roundLabel('single_elimination', null, 1, 4)).toBe('Round 1');
        // 2 teams: one round, and it is the final.
        expect(roundLabel('single_elimination', null, 1, 1)).toBe(
            'Final round',
        );
        // Other formats count rounds.
        expect(roundLabel('round_robin', null, 3, 3)).toBe('Round 3');
        expect(roundLabel('swiss', null, 5, 5)).toBe('Round 5');
    });

    it('names a group by its letter', () => {
        expect(sectionLabel('group-a')).toBe('Group A');
        expect(sectionLabel(null)).toBeNull();
    });

    it('calls an awaited slot open and an unfillable one a bye', () => {
        expect(sideName(side(2, null, true))).toBe('Open');
        expect(sideName(side(2, null, false))).toBe('Bye');
    });
});

describe('the canvas and the table show the same pairings', () => {
    it("places every round the table lists, in the table's order", () => {
        const sections = bracketSections(fourTeams);
        const placed = layoutBracket(sections);

        // The table: one row per match, round by round.
        const table = sections.flatMap((s) =>
            s.rounds.map((r) => r.matches.map((m) => `${m.id}: ${pairing(m)}`)),
        );

        // The canvas: one column per round, matches top to bottom.
        const columns = [0, 1].map((column) =>
            placed
                .filter((p) => p.column === column)
                .sort((a, b) => a.y - b.y)
                .map((p) => `${p.match.id}: ${pairing(p.match)}`),
        );

        expect(columns).toEqual(table);
        expect(columns).toEqual([
            ['semi-1: A v D', 'semi-2: B v C'],
            ['final: Open v Open'],
        ]);
    });

    it('centres a match between the two it is fed from', () => {
        const placed = layoutBracket(bracketSections(fourTeams));
        const at = (id: string) => placed.find((p) => p.match.id === id)!;

        // The widest round has 2 matches: height = 2 * (60 + 20) - 20 = 140.
        //   round 1: spacing 70 — y = 0 + (70 - 60) / 2 = 5, and 70 + 5 = 75
        //   round 2: spacing 140 — y = (140 - 60) / 2 = 40
        // Centres: 35 and 105 in round 1, and 70 = (35 + 105) / 2 in round 2.
        expect(at('semi-1').y).toBe(5);
        expect(at('semi-2').y).toBe(75);
        expect(at('final').y).toBe(40);
        expect(at('final').x).toBe(MATCH_WIDTH + ROUND_GAP_X);
        expect(at('final').y + MATCH_HEIGHT / 2).toBe(
            (at('semi-1').y + at('semi-2').y + MATCH_HEIGHT) / 2,
        );
        expect(MATCH_HEIGHT + MATCH_GAP_Y).toBe(80);
    });

    it('puts a grand final to the right of the brackets it closes', () => {
        const placed = layoutBracket(
            bracketSections({
                format: 'double_elimination',
                matches: [
                    match('w1', 'winners', 1, 1, ['A', 'B']),
                    match('w2', 'winners', 2, 1, [null, null]),
                    match('l1', 'losers', 1, 1, [null, null]),
                    match('gf', 'grand_final', 1, 1, [null, null]),
                ],
                connections: [],
            }),
        );

        // Winners has two rounds, losers one: the final is in column 2.
        expect(placed.find((p) => p.match.id === 'gf')!.column).toBe(2);
        expect(placed.map((p) => p.match.id).sort()).toEqual([
            'gf',
            'l1',
            'w1',
            'w2',
        ]);
    });
});
