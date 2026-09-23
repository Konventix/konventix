import { describe, expect, it } from 'vitest';

/**
 * Inertia 3's `<Form>` takes visit options through its `options` prop
 * only. `preserve-scroll` and `preserve-state` are `<Link>` props; written
 * on a `<Form>` they fall through as plain HTML attributes on the `<form>`
 * element and do nothing, silently. `SeatPickForm` shipped that way (#317):
 * the seat picker was meant to stay where it was after a save and jumped
 * to the top instead. The repo's idiom is `:options="{ preserveScroll:
 * true }"`.
 *
 * Every component's source is read as text through `import.meta.glob`
 * (as `toasterOffset.test.ts` does), because a no-op attribute is invisible
 * at runtime: nothing throws and nothing warns.
 */
const sources = {
    ...import.meta.glob<string>('/resources/js/**/*.vue', {
        query: '?raw',
        import: 'default',
        eager: true,
    }),
    ...import.meta.glob<string>('/app-modules/*/resources/js/**/*.vue', {
        query: '?raw',
        import: 'default',
        eager: true,
    }),
};

/**
 * The opening tag of every `<Form>` in a component's template, up to the
 * first `>` outside a quoted attribute value — an attribute may hold an
 * arrow function, whose `=>` must not end the tag. The script block is
 * skipped, so a docblock that mentions `<Form>` is not read as one.
 */
function formOpeningTags(component: string): string[] {
    // The top-level block starts a line; a nested `<template #slot>` or a
    // docblock's mention of one is indented.
    const templateStart = component.search(/^<template/m);
    const source = templateStart === -1 ? '' : component.slice(templateStart);
    const tags: string[] = [];
    const start = /<Form(?=[\s>])/g;
    let match: RegExpExecArray | null;

    while ((match = start.exec(source)) !== null) {
        let quote: string | null = null;
        let end = match.index;

        for (; end < source.length; end++) {
            const char = source[end];

            if (quote !== null) {
                quote = char === quote ? null : quote;
            } else if (char === '"' || char === "'") {
                quote = char;
            } else if (char === '>') {
                break;
            }
        }

        tags.push(source.slice(match.index, end + 1));
    }

    return tags;
}

/** A `<Link>`-only visit option written as an attribute, bound or not. */
const LINK_ONLY_ATTRIBUTE =
    /\s:?(?:preserve-scroll|preserveScroll|preserve-state|preserveState)(?=[\s=>])/;

/**
 * The attribute names of an opening tag, with every quoted value emptied —
 * `:options="{ preserveScroll: true }"` is the right way and must not read
 * as the wrong one.
 */
function attributeNames(tag: string): string {
    return tag.replace(/"[^"]*"|'[^']*'/g, '""');
}

describe('Inertia <Form> visit options', () => {
    it('finds the forms it is meant to check', () => {
        const forms = Object.values(sources).flatMap(formOpeningTags);

        expect(forms.length).toBeGreaterThan(0);
    });

    it('never passes preserve-scroll or preserve-state as a <Form> attribute', () => {
        const offenders = Object.entries(sources).flatMap(([path, source]) =>
            formOpeningTags(source)
                .filter((tag) => LINK_ONLY_ATTRIBUTE.test(attributeNames(tag)))
                .map(() => path),
        );

        expect(offenders).toEqual([]);
    });

    it('keeps the seat picker scrolled where it was after a save (#317)', () => {
        const seatPickForm =
            sources[
                '/app-modules/resources/resources/js/components/plan/SeatPickForm.vue'
            ];

        expect(seatPickForm).toBeDefined();

        const [tag] = formOpeningTags(seatPickForm ?? '');

        expect(tag).toContain(':options="{ preserveScroll: true }"');
    });
});
