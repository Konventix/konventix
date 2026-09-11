import {
    Accessibility,
    Armchair,
    Circle,
    Crown,
    Gamepad2,
    Laptop,
    Monitor,
    Sofa,
    Star,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Category symbols (design §4.1: a lucide key, mandatory in the picker so
 * category is never told by colour alone — BFSG). The console offers
 * these keys; an unknown one falls back to a plain circle rather than to
 * nothing, the same rule as `navIcons.ts`.
 */
const symbolMap: Record<string, Component> = {
    accessibility: Accessibility,
    armchair: Armchair,
    crown: Crown,
    'gamepad-2': Gamepad2,
    laptop: Laptop,
    monitor: Monitor,
    sofa: Sofa,
    star: Star,
    users: Users,
};

export const SYMBOL_KEYS = Object.keys(symbolMap);

export function symbolFor(symbol: string | null): Component {
    return (symbol !== null && symbolMap[symbol]) || Circle;
}
