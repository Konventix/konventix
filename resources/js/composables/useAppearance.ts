import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { functionalCookiesAllowed, readCookie } from '@/lib/cookiePreferences';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    // Functional storage refused: a session cookie only (exempt as
    // short-term UI customisation, WP194 §3.6) — the choice resets when
    // the browser closes rather than breaking the appearance toggle.
    const persistence = functionalCookiesAllowed()
        ? `max-age=${days * 24 * 60 * 60};`
        : '';

    document.cookie = `${name}=${value};path=/;${persistence}SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    if (!functionalCookiesAllowed()) {
        return readCookie('appearance') as Appearance | null;
    }

    try {
        return localStorage.getItem('appearance') as Appearance | null;
    } catch {
        // Site data blocked or unavailable — fall back to the cookie rather
        // than breaking the toggle.
        return readCookie('appearance') as Appearance | null;
    }
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = getStoredAppearance();

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence, but only once
        // functional storage is allowed — otherwise this outlives the
        // session it was meant to be scoped to. Wrapped: a browser that
        // blocks site data throws on access, and that must not break the
        // toggle.
        try {
            if (functionalCookiesAllowed()) {
                localStorage.setItem('appearance', value);
            } else {
                localStorage.removeItem('appearance');
            }
        } catch {
            // Nothing persisted; the cookie below still carries the choice.
        }

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
