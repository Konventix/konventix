<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Brands\BrandContext;
use Psr\Http\Message\StreamInterface;
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

    /**
     * How much of the response is held at a time. The point of the streamed
     * read is that nothing bigger than this ever exists in the worker, so the
     * ceiling above costs the ceiling and not whatever the far end chose to
     * send.
     */
    private const CHUNK_BYTES = 64 * 1024;

    /**
     * The whole fetch, connect through last byte. `Http::timeout()` used to
     * mean exactly that: without `stream => true` the request goes through
     * Guzzle's cURL handler, where `timeout` is `CURLOPT_TIMEOUT`, a cap on the
     * entire transfer. With `stream => true` it goes through `StreamHandler`
     * instead, where `timeout` becomes the PHP stream context's idle timeout —
     * re-armed by every byte received, so a host answering one byte every nine
     * seconds is never cut off. `read_timeout` (the option `StreamHandler`
     * keys `stream_set_timeout()` off) bounds each read, and the deadline
     * carried through {@see self::streamToFile()} bounds the sum of them.
     */
    private const FETCH_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly UpdateMembershipImage $images,
        private readonly StoreMediaUpload $uploads,
        /**
         * Overridable so a test can watch the deadline fire without sleeping
         * for the production bound. Nothing in the application passes it.
         */
        private readonly float $timeoutSeconds = self::FETCH_TIMEOUT_SECONDS,
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

        // The clock starts before the request, not after it: the bound is on the
        // whole fetch, and the headers are part of it.
        $deadline = hrtime(true) + (int) round($this->timeoutSeconds * 1_000_000_000);

        try {
            // No redirect following: an allowlisted first hop that answers 302
            // would otherwise walk the fetch straight off the allowlist.
            //
            // `stream => true` is what makes the size ceiling below mean
            // anything. Without it the client materialises the whole body
            // before a single check can run, and an allowlisted host answering
            // with an unbounded response costs a long-lived Octane worker its
            // entire memory_limit — a failure that outlives the one request.
            // With it, only the headers have arrived at this point and the body
            // is read a chunk at a time. `read_timeout` comes with it: see
            // self::FETCH_TIMEOUT_SECONDS for why `timeout` alone stops meaning
            // what it meant the moment the handler changes.
            $response = Http::timeout($this->timeoutSeconds)
                ->withoutRedirecting()
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $this->timeoutSeconds,
                ])
                ->get($identity->avatar_url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $body = $response->toPsrResponse()->getBody();
        $mime = $response->header('Content-Type');
        $declaredLength = $response->header('Content-Length');

        // Judged before anything is read: a body that announces itself as too
        // large is refused without being fetched at all.
        if (! $response->successful()
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || ($declaredLength !== '' && (int) $declaredLength > self::MAX_AVATAR_BYTES)) {
            // `stream => true` means this is a live socket, not a string that
            // has already arrived. Closed here rather than left to the
            // destructor, on every path that gives up before reading.
            $body->close();

            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $path = tempnam(sys_get_temp_dir(), 'steam-avatar');

        if ($path === false) {
            $body->close();

            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        try {
            // The backstop for a chunked response, or one that lies about its
            // length: the counter stops at the ceiling whatever the header said.
            //
            // With the body no longer arriving inside `get()`, a connection
            // reset mid-transfer surfaces here as a RuntimeException out of
            // `Stream::read()` — where before it was a TransferException caught
            // above. It is the same failure and gets the same answer: no avatar
            // change, a warning in the log, and the 'steam'-keyed message the
            // rest of this method produces. Not a 500.
            try {
                $copied = $this->streamToFile($body, $path, $deadline);
            } catch (Throwable $e) {
                Log::warning('The Steam avatar download failed mid-stream.', [
                    'user_id' => $user->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                $copied = false;
            }

            if (! $copied) {
                throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
            }

            try {
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
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Copies the response body into `$path`, reading at most
     * {@see self::CHUNK_BYTES} at a time and giving up the moment more than
     * {@see self::MAX_AVATAR_BYTES} has arrived — so the worker never holds
     * more than the ceiling, whatever the far end intended to send — or the
     * moment `$deadline` (an `hrtime(true)` reading) passes, so a body that
     * trickles in just inside every idle window cannot hold the worker either.
     *
     * Answers false when the body was over the ceiling, took too long, or could
     * not be written, in which case the partial file is thrown away by the
     * caller. The handle and the response stream are closed on every exit,
     * including the one an exception takes.
     */
    private function streamToFile(StreamInterface $body, string $path, int $deadline): bool
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $body->close();

            return false;
        }

        $written = 0;

        try {
            while (! $body->eof()) {
                if (hrtime(true) > $deadline) {
                    Log::warning('The Steam avatar download passed its deadline.', [
                        'bytes' => $written,
                        'seconds' => $this->timeoutSeconds,
                    ]);

                    return false;
                }

                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > self::MAX_AVATAR_BYTES) {
                    return false;
                }

                if (fwrite($handle, $chunk) === false) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
            $body->close();
        }

        return $written > 0;
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
