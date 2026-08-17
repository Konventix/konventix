import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    destroy as destroyFavorite,
    reorder as reorderFavorites,
    store as storeFavorite,
} from '@/actions/Konventix/Platform/Http/Controllers/Admin/AdminFavoriteController';

/**
 * User-pinned operator-console shortcuts (ADR-0029), ported concept from
 * LanCore's sidebar favorites. A favorite is identified by its URL — the
 * shell has no other stable id for a `NavigationItem`, and the backend's
 * `admin_favorites` table is unique on (user_id, url).
 *
 * The shared `admin.favorites` prop arrives in the user's own order
 * (ADR-0042) — never re-sort it client-side.
 */
export type AdminFavorite = {
    id: string;
    label: string;
    url: string;
};

export function useAdminFavorites() {
    const page = usePage();

    const favorites = computed<AdminFavorite[]>(
        () =>
            (
                page.props.admin as {
                    favorites?: AdminFavorite[];
                } | null
            )?.favorites ?? [],
    );

    function isPinned(url: string): boolean {
        return favorites.value.some((favorite) => favorite.url === url);
    }

    function toggle(item: { label: string; url: string }): void {
        const existing = favorites.value.find(
            (favorite) => favorite.url === item.url,
        );

        if (existing) {
            router.delete(destroyFavorite(existing.id).url, {
                preserveScroll: true,
                preserveState: true,
            });

            return;
        }

        router.post(
            storeFavorite().url,
            { label: item.label, url: item.url },
            { preserveScroll: true, preserveState: true },
        );
    }

    /**
     * Persist a drag-reorder (ADR-0042). The list is sent whole, top first —
     * positions are derived server-side, so a stale index can never leave two
     * favorites fighting over one slot.
     */
    function reorder(ids: string[]): void {
        if (ids.length === 0) {
            return;
        }

        router.put(
            reorderFavorites().url,
            { ids },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { favorites, isPinned, toggle, reorder };
}
