import { describe, expect, it } from 'vitest';
import { reactive } from 'vue';

import { freshBlockData } from './blockDefaults';

/**
 * A block type's `defaultData` reaches the composer as an Inertia page prop,
 * i.e. wrapped in a Vue reactive proxy. `structuredClone` refuses proxies
 * (`DataCloneError`), which is exactly what made "Add block" a dead click:
 * the handler threw before it could emit. The fresh payload has to be a
 * plain, detached copy regardless of how the default arrived.
 */
describe('freshBlockData', () => {
    it('copies a reactive default into a plain object the composer can mutate', () => {
        const type = reactive({
            defaultData: { cards: [{ title: '', body: '', icon: null }] },
        });

        const data = freshBlockData(type.defaultData);

        expect(data).toEqual({ cards: [{ title: '', body: '', icon: null }] });

        (data.cards as Array<{ title: string }>)[0].title = 'changed';

        expect(type.defaultData.cards[0].title).toBe('');
    });

    it('returns a new object every time so two added blocks never share state', () => {
        const defaults = { heading: null, body: '' };

        expect(freshBlockData(defaults)).not.toBe(freshBlockData(defaults));
    });
});
