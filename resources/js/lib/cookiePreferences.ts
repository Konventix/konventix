/**
 * The visitor's choice about functional storage, read straight from
 * `document.cookie` so it is available before Vue mounts (the theme is
 * applied in `app.ts` before the first paint) and immediately after the
 * dialog saved it. `null` = no choice made yet, which permits functional
 * storage that the person switches on at a labelled control (WP194 §3.6).
 */
export function readFunctionalPreference(): boolean | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(
        /(?:^|;\s*)cookie_preferences=functional=([01])/,
    );

    return match ? match[1] === '1' : null;
}

export function functionalCookiesAllowed(): boolean {
    return readFunctionalPreference() !== false;
}

export function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(
        new RegExp(`(?:^|;\\s*)${name}=([^;]*)`),
    );

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Withdraws functional storage immediately (Art. 7(3) GDPR: as easy to take
 * back as it was to give). `localStorage.removeItem` throws in a browser
 * that blocks site data, so the removal is best-effort — the appearance
 * toggle falls back to the cookie/`system` either way.
 */
export function clearFunctionalStorage(): void {
    try {
        localStorage.removeItem('appearance');
    } catch {
        // Site data blocked or unavailable — nothing was persisted to clear.
    }
}
