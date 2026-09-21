import { describe, expect, it } from 'vitest';
import {
    bindingDisplay,
    bindingRuleSentence,
    groupBindings,
} from './policyBindings';
import type { BindingKind, BindingRow } from './policyBindings';

const kinds: BindingKind[] = [
    { value: 'event', label: 'Event', plural: 'events' },
    {
        value: 'registration_type',
        label: 'Registration type',
        plural: 'registration types',
    },
    {
        value: 'settlement_provider',
        label: 'Payment provider',
        plural: 'payment providers',
    },
];

function row(overrides: Partial<BindingRow> = {}): BindingRow {
    return {
        id: '01HX',
        kind: 'event',
        kindLabel: 'Event',
        targetKey: '01HXEVENT',
        label: 'Summer LAN 2026',
        ...overrides,
    };
}

describe('bindingDisplay', () => {
    it('names a labelled target', () => {
        expect(bindingDisplay(row())).toBe('Summer LAN 2026');
    });

    it('renders an unlabelable target rather than hiding it', () => {
        expect(bindingDisplay(row({ label: null }))).toBe(
            'Unknown (event · 01HXEVENT)',
        );
    });
});

describe('groupBindings', () => {
    it('groups by kind in the declared order and drops empty kinds', () => {
        const groups = groupBindings(
            [
                row({
                    id: 'a',
                    kind: 'settlement_provider',
                    targetKey: 'stripe',
                    label: 'Stripe',
                }),
                row({ id: 'b' }),
            ],
            kinds,
        );

        expect(groups.map((group) => group.kind)).toEqual([
            'event',
            'settlement_provider',
        ]);
        expect(groups[0].bindings[0].display).toBe('Summer LAN 2026');
    });
});

describe('bindingRuleSentence', () => {
    it('says nothing while only one kind is bound', () => {
        expect(bindingRuleSentence([row()], kinds)).toBeNull();
    });

    it('spells out the AND across kinds once a second one is bound', () => {
        const sentence = bindingRuleSentence(
            [
                row(),
                row({
                    id: 'b',
                    kind: 'settlement_provider',
                    targetKey: 'stripe',
                    label: 'Stripe',
                }),
            ],
            kinds,
        );

        expect(sentence).toBe(
            'Applies when any of the events match and any of the payment providers match. A kind with no binding places no requirement.',
        );
    });
});
