/**
 * The two pure decisions the "Where this applies" card makes (ADR-0066
 * §9.2), out of the template so they can be tested without rendering
 * anything — vitest runs in the `node` environment here.
 */
import { trans } from 'laravel-vue-i18n';

export type BindingKind = {
    value: string;
    /** Singular, for the chip group's heading. */
    label: string;
    /** Plural, for the rule sentence. */
    plural: string;
};

export type BindingRow = {
    id: string;
    kind: string;
    kindLabel: string;
    targetKey: string;
    /** Null when Platform's registry cannot name the target any more. */
    label: string | null;
};

export type DisplayedBinding = BindingRow & { display: string };

export type BindingGroup = {
    kind: string;
    label: string;
    bindings: DisplayedBinding[];
};

/**
 * What a chip reads. A target the registry cannot name is not hidden and not
 * an error: it never matches, and the console is the one place it can be
 * removed — so it says exactly what it is (spec §9.2).
 */
export function bindingDisplay(binding: BindingRow): string {
    return (
        binding.label ??
        trans('Unknown (:kind · :target)', {
            kind: binding.kind,
            target: binding.targetKey,
        })
    );
}

/**
 * Bindings as chips grouped by kind, in the order the kinds were declared —
 * so two policies never head their cards differently.
 */
export function groupBindings(
    bindings: BindingRow[],
    kinds: BindingKind[],
): BindingGroup[] {
    return kinds
        .map((kind) => ({
            kind: kind.value,
            label: kind.label,
            bindings: bindings
                .filter((binding) => binding.kind === kind.value)
                .map((binding) => ({
                    ...binding,
                    display: bindingDisplay(binding),
                })),
        }))
        .filter((group) => group.bindings.length > 0);
}

/**
 * The matching rule in words (D-CPL-03), or null while fewer than two kinds
 * are bound and there is no combination to explain.
 *
 * The sentence is the resolver's rule verbatim — OR within a kind, AND
 * across kinds. It narrows, unlike everything else in this model, which is
 * why it is printed rather than left to be inferred.
 */
export function bindingRuleSentence(
    bindings: BindingRow[],
    kinds: BindingKind[],
): string | null {
    const bound = kinds.filter((kind) =>
        bindings.some((binding) => binding.kind === kind.value),
    );

    if (bound.length < 2) {
        return null;
    }

    const clauses = bound.map((kind) =>
        trans('any of the :kinds match', { kinds: kind.plural }),
    );

    return trans(
        'Applies when :clauses. A kind with no binding places no requirement.',
        { clauses: clauses.join(` ${trans('and')} `) },
    );
}
