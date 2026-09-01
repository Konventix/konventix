<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Konventix\Identity\Actions\CopySteamAvatar;
use Konventix\Identity\Actions\UpdateMembershipImage;

/**
 * The member's avatar and banner for the current brand (ADR-0051 §1) —
 * upload and removal, one action per image kind, both driven by
 * {@see UpdateMembershipImage}; `copySteam` pulls the avatar from a linked
 * Steam identity instead of an uploaded file, driven by
 * {@see CopySteamAvatar}.
 */
final class MembershipImageController
{
    public function storeAvatar(Request $request, UpdateMembershipImage $action): RedirectResponse
    {
        return $this->store($request, $action, 'avatar', 5120);
    }

    public function copySteam(Request $request, CopySteamAvatar $copy): RedirectResponse
    {
        $copy->handle($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile image updated.')]);

        return to_route('profile.edit');
    }

    public function destroyAvatar(Request $request, UpdateMembershipImage $action): RedirectResponse
    {
        return $this->destroy($request, $action, 'avatar');
    }

    public function storeBanner(Request $request, UpdateMembershipImage $action): RedirectResponse
    {
        return $this->store($request, $action, 'banner', 10240);
    }

    public function destroyBanner(Request $request, UpdateMembershipImage $action): RedirectResponse
    {
        return $this->destroy($request, $action, 'banner');
    }

    private function store(Request $request, UpdateMembershipImage $action, string $kind, int $maxKilobytes): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', "max:{$maxKilobytes}", 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $action->set($request->user(), $kind, $validated['file']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile image updated.')]);

        return to_route('profile.edit');
    }

    private function destroy(Request $request, UpdateMembershipImage $action, string $kind): RedirectResponse
    {
        $action->remove($request->user(), $kind);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile image updated.')]);

        return to_route('profile.edit');
    }
}
