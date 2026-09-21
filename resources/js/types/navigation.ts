import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};

/**
 * A brand-shell navigation entry as the backend's NavigationRegistry emits
 * it (ADR-0003): the icon arrives as a name, not a component, because a
 * module contributes entries from PHP and knows nothing about the shell.
 */
export type BrandNavItem = {
    module: string;
    label: string;
    url: string;
    icon: string | null;
    order: number;
    /**
     * Not an Inertia page (Horizon, Pulse, the health page): render a plain
     * anchor so the browser loads it whole. An Inertia visit would fetch the
     * HTML over XHR and show it in the error modal.
     */
    external: boolean;
};

/**
 * One entry of the brand's own menu (Task 20), as the shared `brandMenu`
 * prop carries it: already resolved for the viewer on the server, so the
 * shell renders what it is given and holds no visibility rule. A parent
 * has `url: null` and children; a leaf has a URL and none.
 */
export type BrandMenuEntry = Konventix.Platform.Data.BrandMenuEntryData;
