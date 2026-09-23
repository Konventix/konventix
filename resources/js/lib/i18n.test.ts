import { describe, expect, it } from 'vitest';
import { catalogFor, chainFrom } from './i18n';

/**
 * ADR-0068 §3 and §8: every module's file for a locale is one map, the
 * root file loads first so a module may refine it, and a variant reads its
 * base beneath itself.
 */
describe('catalogFor', () => {
    const files = {
        '/lang/de.json': { Save: 'Speichern', Cancel: 'Abbrechen' },
        '/app-modules/commerce/lang/de.json': {
            'Add to cart': 'In den Warenkorb',
        },
        '/app-modules/identity/lang/de.json': { 'Sign in': 'Melde dich an' },
        '/app-modules/identity/lang/de_formal.json': {
            'Sign in': 'Melden Sie sich an',
        },
    };

    it('merges every file of the locale', () => {
        expect(catalogFor(['de', 'en'], files)).toEqual({
            Save: 'Speichern',
            Cancel: 'Abbrechen',
            'Add to cart': 'In den Warenkorb',
            'Sign in': 'Melde dich an',
        });
    });

    it('overlays a variant on its base', () => {
        const catalog = catalogFor(['de_formal', 'de', 'en'], files);

        expect(catalog['Sign in']).toBe('Melden Sie sich an');
        expect(catalog.Save).toBe('Speichern');
    });

    it('answers an empty map for the source locale', () => {
        expect(catalogFor(['en'], files)).toEqual({});
    });
});

/**
 * The plugin caches what `resolve` returns by the locale it asked for, in
 * module state the SSR process shares across requests — so the answer for
 * `en` must be English whatever chain the first request carried.
 */
describe('chainFrom', () => {
    it('starts the chain at the requested locale', () => {
        expect(chainFrom(['de_formal', 'de', 'en'], 'de')).toEqual([
            'de',
            'en',
        ]);
        expect(chainFrom(['de', 'en'], 'en')).toEqual(['en']);
    });

    it('answers the locale alone when it is not in the chain', () => {
        expect(chainFrom(['de', 'en'], 'php_de')).toEqual(['php_de']);
    });
});
