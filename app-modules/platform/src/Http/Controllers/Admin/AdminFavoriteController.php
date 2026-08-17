<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Data\AdminFavoriteData;
use Konventix\Platform\Data\AdminFavoriteOrderData;
use Konventix\Platform\Models\AdminFavorite;

/**
 * Operator console: per-user pinned admin shortcuts (ADR-0029), ported
 * concept from LanCore's NavFavorites + SidebarFavoriteController. Route
 * middleware already requires the admin host, auth and
 * platform.access-admin; favorites are isolated per user_id below, since
 * pinning is a personal preference, not a capability.
 */
class AdminFavoriteController
{
    public function store(AdminFavoriteData $data, Request $request): RedirectResponse
    {
        $userId = $request->user()->getKey();

        AdminFavorite::query()->firstOrCreate(
            ['user_id' => $userId, 'url' => $data->url],
            [
                'label' => $data->label,
                // A new pin lands at the end of the user's order (ADR-0042);
                // reordering is an explicit drag, never a side effect of
                // pinning.
                'position' => (int) AdminFavorite::query()
                    ->where('user_id', $userId)
                    ->max('position') + 1,
            ],
        );

        return back();
    }

    /**
     * Persist a drag-reorder of the sidebar favorites (ADR-0042). Ids the user
     * does not own are dropped rather than fatal — a second tab may have
     * unpinned one since this list was rendered. Favorites missing from the
     * payload keep their stored position; the model's label tie-breaker
     * settles any collision that causes.
     */
    public function reorder(AdminFavoriteOrderData $data, Request $request): RedirectResponse
    {
        $userId = $request->user()->getKey();

        $ownedIds = AdminFavorite::query()
            ->where('user_id', $userId)
            ->whereIn('id', $data->ids)
            ->pluck('id')
            ->all();

        $ordered = array_values(array_filter(
            $data->ids,
            fn (string $id): bool => in_array($id, $ownedIds, true),
        ));

        DB::transaction(function () use ($ordered, $userId): void {
            foreach ($ordered as $index => $id) {
                AdminFavorite::query()
                    ->where('user_id', $userId)
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        });

        return back();
    }

    public function destroy(Request $request, AdminFavorite $favorite): RedirectResponse
    {
        abort_unless($favorite->user_id === $request->user()->getKey(), 404);

        $favorite->delete();

        return back();
    }
}
