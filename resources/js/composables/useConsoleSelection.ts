import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The operator's current pick of brand and event in the console shell
 * (ADR-0029), shared on every admin-host page as `admin.selection`. Read-only
 * here — `AdminSelectionBar` owns writing it — and null off the admin host,
 * where there is no operator context to share it from.
 *
 * Lists that span brands or events read it as the default of their own
 * filter; see `seedSelectionFilter()` in `useDataTable.ts` for why they also
 * need to know whether a selection exists at all.
 */
export type ConsoleSelection = {
    brandId: string | null;
    eventId: string | null;
    brands: { id: string; name: string }[];
    events: { id: string; name: string; brandId: string }[];
    eventsAvailable: boolean;
};

export function useConsoleSelection() {
    const page = usePage();

    return computed<ConsoleSelection | null>(
        () =>
            (
                page.props.admin as {
                    selection?: ConsoleSelection;
                } | null
            )?.selection ?? null,
    );
}
