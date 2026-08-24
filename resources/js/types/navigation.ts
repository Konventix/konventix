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
};
