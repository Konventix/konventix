<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Data\ProfileUpdateData;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Http\Requests\ProfileDeleteRequest;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;

class ProfileController
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, MediaLibrary $media): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'tagline' => $user->tagline,
                'statusEmoji' => $user->status_emoji,
                'socialLinks' => $user->social_links ?? (object) [],
                'visibility' => $user->profile_visibility->value,
            ],
            'membershipImages' => $this->membershipImages($user, $media),
            'steamAvatarUrl' => $this->steamAvatarUrl($user),
        ]);
    }

    /**
     * The avatar url on the user's linked Steam identity, if any — not the
     * brand-copied avatar (`membershipImages.avatarUrl`), the source Task
     * 5's "Use my Steam avatar" button offers to copy from.
     */
    private function steamAvatarUrl(User $user): ?string
    {
        return $user->socialIdentities()->where('provider', 'steam')->first()?->avatar_url;
    }

    /**
     * The current brand membership's avatar/banner URLs (ADR-0051 §1) — both
     * null when there is no brand in context or the account has not yet
     * become a member here.
     *
     * @return array{avatarUrl: string|null, bannerUrl: string|null}
     */
    private function membershipImages(User $user, MediaLibrary $media): array
    {
        $membership = BrandMembership::query()->where('user_id', $user->getKey())->first();

        if ($membership === null) {
            return ['avatarUrl' => null, 'bannerUrl' => null];
        }

        return [
            'avatarUrl' => $this->urlOrNull($media, $membership->avatar_media_id),
            'bannerUrl' => $this->urlOrNull($media, $membership->banner_media_id),
        ];
    }

    private function urlOrNull(MediaLibrary $media, ?string $mediaId): ?string
    {
        return $mediaId === null ? null : ($media->urlFor($mediaId, 'thumb') ?? $media->urlFor($mediaId));
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request, ProfileUpdateData $data): RedirectResponse
    {
        $request->user()->fill([
            'name' => $data->name,
            'email' => $data->email,
            'username' => $data->username,
            'tagline' => $data->tagline !== '' ? $data->tagline : null,
            'status_emoji' => $data->statusEmoji !== '' ? $data->statusEmoji : null,
            'social_links' => array_filter($data->socialLinks ?? []) ?: null,
            'profile_visibility' => ProfileVisibility::from($data->visibility),
        ]);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // Contract event for read-model consumers (SPEC.md, ADR-0004) —
        // carries the current values, so replays project idempotently.
        event(new UserProfileUpdated(
            userId: $request->user()->id,
            email: $request->user()->email,
            displayName: $request->user()->name,
            username: $request->user()->username,
            tagline: $request->user()->tagline,
            statusEmoji: $request->user()->status_emoji,
            socialLinks: $request->user()->social_links ?? [],
            visibility: $request->user()->profile_visibility->value,
            locale: $request->user()->locale,
            updatedAt: now()->toIso8601String(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
