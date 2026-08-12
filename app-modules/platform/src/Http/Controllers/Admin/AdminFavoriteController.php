<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Konventix\Platform\Data\AdminFavoriteData;
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
        AdminFavorite::query()->firstOrCreate(
            ['user_id' => $request->user()->getKey(), 'url' => $data->url],
            ['label' => $data->label],
        );

        return back();
    }

    public function destroy(Request $request, AdminFavorite $favorite): RedirectResponse
    {
        abort_unless($favorite->user_id === $request->user()->getKey(), 404);

        $favorite->delete();

        return back();
    }
}
