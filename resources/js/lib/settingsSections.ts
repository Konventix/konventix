import {
    Bell,
    Link2,
    Palette,
    ShieldCheck,
    ShieldUser,
    UserRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editLinkedAccounts } from '@/routes/linked-accounts';
import { edit as editNotifications } from '@/routes/notification-preferences';
import { edit as editPrivacy } from '@/routes/privacy';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

export type SettingsSection = {
    title: string;
    description: string;
    href: ReturnType<typeof editProfile>;
    icon: Component;
};

/**
 * The account's settings sections, in one list for the two things that
 * render them: the hub page at `/settings` and the sub-navigation beside
 * every settings page.
 *
 * Several of these belong to other modules — notification preferences are
 * Messaging's, privacy is Compliance's — and are listed here rather than
 * registered, matching how the sub-navigation has always worked. If a third
 * consumer or a gateable module ever needs a say, this becomes a registry
 * like NavigationRegistry; today that would be machinery for one list.
 *
 * `title` and `description` are catalog keys (ADR-0068), not text: this is
 * a module-level constant, evaluated before the catalog is installed, so
 * every consumer renders them through `$t()`.
 */
export const settingsSections: SettingsSection[] = [
    {
        title: 'Profile',
        description: 'Your name, email address and account deletion.',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        description:
            'Password, two-factor authentication and your active sessions.',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Linked accounts',
        description: 'Sign-in providers connected to this account.',
        href: editLinkedAccounts(),
        icon: Link2,
    },
    {
        title: 'Notifications',
        description: 'Which messages reach you, and on which channel.',
        href: editNotifications(),
        icon: Bell,
    },
    {
        title: 'Privacy',
        description: 'Consents, your data export and deletion requests.',
        href: editPrivacy(),
        icon: ShieldUser,
    },
    {
        title: 'Appearance',
        description: 'Light, dark or whatever this device prefers.',
        href: editAppearance(),
        icon: Palette,
    },
];
