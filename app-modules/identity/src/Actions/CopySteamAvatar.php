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

        try {
            $response = Http::timeout(10)->get($identity->avatar_url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $mime = $response->header('Content-Type');

        if (! $response->successful() || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $path = tempnam(sys_get_temp_dir(), 'steam-avatar');

        try {
            file_put_contents($path, $response->body());

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
}
