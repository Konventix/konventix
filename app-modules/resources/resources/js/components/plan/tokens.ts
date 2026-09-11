/**
 * `CategoryToken` → the CSS custom property it names (design §4.1). Only
 * `var(--…)` references leave this file: `konventix/no-palette-colors`
 * rejects any literal colour, and a brand re-colours these at runtime by
 * redeclaring the property (resources/css/app.css, layer 1).
 */
type CategoryToken = Konventix.Resources.Enums.CategoryToken;

const TOKEN_VARS: Record<CategoryToken, string> = {
    'chart-1': 'var(--chart-1)',
    'chart-2': 'var(--chart-2)',
    'chart-3': 'var(--chart-3)',
    'chart-4': 'var(--chart-4)',
    'chart-5': 'var(--chart-5)',
    accent: 'var(--accent)',
    success: 'var(--success)',
    warning: 'var(--warning)',
    danger: 'var(--danger)',
};

export function tokenVar(token: CategoryToken | null): string {
    return token === null ? TOKEN_VARS['chart-1'] : TOKEN_VARS[token];
}

/** Fills that say state, not category — colour is never the only signal (ADR-0055). */
export const STATE_FILL = {
    mine: 'var(--success)',
    taken: 'var(--surface-muted)',
    unavailable: 'var(--surface-muted)',
    selectedStroke: 'var(--accent)',
    outline: 'var(--border)',
    zone: 'var(--surface-muted)',
    text: 'var(--text)',
    textMuted: 'var(--text-muted)',
} as const;
