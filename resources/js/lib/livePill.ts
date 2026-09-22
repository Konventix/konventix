export type LivePill = {
    label: string;
    href: string;
    onSite: boolean;
};

/**
 * What the topbar shows an admitted participant: a pill that names the event
 * and leads to the live hub at `/live` (D-HUB-04, design §7.2).
 *
 * The input is the shared `activeEvent` prop `HandleInertiaRequests` puts on
 * every response, so the topbar decides from data and imports nothing from
 * the Admission module. `null` means the viewer is not at an event and the
 * pill stays away; an event without a public name still gets a pill, it just
 * says "Live". `onSite` is passed through for the dot — green while the
 * viewer is on site, muted once they have left.
 */
export function livePillFor(
    activeEvent: Konventix.Platform.Data.ActiveEventData | null,
): LivePill | null {
    if (activeEvent === null) {
        return null;
    }

    return {
        label: activeEvent.eventName ?? 'Live',
        href: '/live',
        onSite: activeEvent.onSite,
    };
}
