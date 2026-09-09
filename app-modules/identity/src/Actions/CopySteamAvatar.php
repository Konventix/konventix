<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Brands\BrandContext;
use Throwable;

/**
 * Copies the avatar from a member's linked Steam identity into the current
 * brand's media library and points the membership at it, once (Task 5) —
 * reuses {@see UpdateMembershipImage::pointAt()} rather than duplicating the
 * usage bookkeeping.
 */
final class CopySteamAvatar
{
    /**
     * Steam's Web API hands avatars out on its own CDN — `avatars.steamstatic.com`
     * and `avatars.akamai.steamstatic.com` are what it returns today, and both
     * sit under this suffix (#259, audit N6).
     */
    private const AVATAR_HOST_SUFFIX = '.steamstatic.com';

    /**
     * The ceiling `MembershipImageController::storeAvatar()` puts on an
     * uploaded avatar (5120 KB). A body that arrives over HTTP has no `max:`
     * rule in front of it, so the number is restated here rather than left to
     * differ between the two ways into the same media library.
     */
    private const MAX_AVATAR_BYTES = 5120 * 1024;

    public function __construct(
        private readonly UpdateMembershipImage $images,
        private readonly StoreMediaUpload $uploads,
    ) {}

    public function handle(User $user): void
    {
        $identity = $user->socialIdentities()->where('provider', 'steam')->first();

        if ($identity === null || $identity->avatar_url === null) {
            throw ValidationException::withMessages(['steam' => __('No Steam avatar is linked to this account.')]);
        }

        // Checked before the request is made, not after it comes back: the
        // value of an allowlist here is that nothing leaves the process for a
        // host nobody vouched for. `avatar_url` is written by Steam's Web API
        // today (SteamProvider::profileFor()), so this is defence in depth —
        // but it is a server-side fetch an authenticated user triggers on
        // demand, against a plain string column (#259).
        if (! $this->isSteamCdnUrl($identity->avatar_url)) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        try {
            // No redirect following: an allowlisted first hop that answers 302
            // would otherwise walk the fetch straight off the allowlist.
            $response = Http::timeout(10)->withoutRedirecting()->get($identity->avatar_url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $mime = $response->header('Content-Type');
        $body = $response->body();

        if (! $response->successful()
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || strlen($body) > self::MAX_AVATAR_BYTES) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $path = tempnam(sys_get_temp_dir(), 'steam-avatar');

        try {
            file_put_contents($path, $body);

            $file = new UploadedFile($path, 'steam-avatar.'.$extension, $mime, null, true);

            $item = $this->uploads->handle(
                brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                file: $file,
                altText: null,
                uploadedByUserId: $user->getKey(),
            );

            $this->images->pointAt($user, 'avatar', $item->getKey());
        } catch (ValidationException $e) {
            // Every failure of this flow surfaces keyed 'steam' — the user
            // clicked a Steam button, not a file input, so an error keyed
            // 'file' (e.g. UpdateMembershipImage::membershipFor()'s
            // no-membership rejection) would point Profile.vue's error
            // rendering at the wrong field. UpdateMembershipImage itself
            // stays 'file'-keyed for the upload flows that legitimately use
            // it; this remaps only at the Steam entry point.
            throw ValidationException::withMessages(['steam' => collect($e->errors())->flatten()->implode(' ')]);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A suffix match on the registrable name, not `str_contains()`: a host
     * like `avatars.steamstatic.com.attacker.example` contains the allowlisted
     * name and is not Steam. The scheme is pinned too — an avatar fetched over
     * plain HTTP is one a network position can replace.
     */
    private function isSteamCdnUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        return $host === 'steamstatic.com' || str_ends_with($host, self::AVATAR_HOST_SUFFIX);
    }
}
