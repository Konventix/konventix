import type { Component } from 'vue';
import { defineAsyncComponent } from 'vue';

/**
 * Registry-driven component resolution for the two places the backend names
 * a Vue component as data: Platform's block registry (`StaticPage.vue`) and
 * the UI slot registry (admin dashboards). The name convention is
 *
 *   `<module>/<path under that module's components dir>`
 *
 * e.g. `platform/blocks/TextBlock`, `event/blocks/CountdownBlock`,
 * `registration/EventStatsCard` — so a module ships its own component and
 * registers the name from its ServiceProvider without any host-side map to
 * edit (ADR-0003: registration is module-local). Host-level components under
 * `resources/js/components` remain addressable by their bare path for
 * anything genuinely shared.
 */
const hostComponents = import.meta.glob<{ default: Component }>(
    '../components/**/*.vue',
);

const moduleComponents = import.meta.glob<{ default: Component }>(
    '/app-modules/*/resources/js/components/**/*.vue',
);

const loaders = new Map<string, () => Promise<{ default: Component }>>();

for (const [path, loader] of Object.entries(hostComponents)) {
    const name = path.replace('../components/', '').replace(/\.vue$/, '');
    loaders.set(name, loader);
}

for (const [path, loader] of Object.entries(moduleComponents)) {
    const match = path.match(
        /^\/app-modules\/([^/]+)\/resources\/js\/components\/(.+)\.vue$/,
    );

    if (!match) {
        continue;
    }

    const name = `${match[1]}/${match[2]}`;

    if (import.meta.env.DEV && loaders.has(name)) {
        throw new Error(
            `Duplicate registered component name "${name}" — a host component collides with ${path}.`,
        );
    }

    loaders.set(name, loader);
}

const resolved = new Map<string, Component>();

/**
 * Resolve a backend-registered component name to an async component, or
 * null when nothing ships under that name (an inactive or not-yet-built
 * module contribution renders nothing rather than erroring — mirrors the
 * renderer's server-side skip of unknown block types).
 */
export function resolveRegisteredComponent(name: string): Component | null {
    const cached = resolved.get(name);

    if (cached) {
        return cached;
    }

    const loader = loaders.get(name);

    if (!loader) {
        return null;
    }

    const component = defineAsyncComponent(loader);
    resolved.set(name, component);

    return component;
}
