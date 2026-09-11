<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Konventix\Admission\Actions\SynchronizeScanBatch;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Http\Middleware\EnsureEntranceDevice;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EntranceSigningKey;

/**
 * The entrance API a scanner talks to (ADR-0033).
 *
 * Every response here is **PII-free by construction**. The delta carries
 * opaque admission IDs, the key set carries public keys, and the calendar
 * carries dates — none of it says anything about a person. That is not a
 * convention to remember, it is the property that lets a scanner sit
 * unattended on a folding table at a venue.
 *
 * The device supplies the brand; the request host does not. Everything scopes
 * explicitly to `$device->brand_id` through `withoutBrandScope()`, ADR-0010's
 * named escape hatch.
 */
final class EntranceController
{
    /**
     * Public keys a device must accept signatures from: currently signing,
     * plus retired-but-unexpired ones whose tokens are still in circulation.
     * Cached at the edge — key rotation is measured in weeks, not seconds.
     */
    public function signingKeys(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $keys = EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $device->brand_id)
            ->publishable()
            ->get()
            ->map(fn (EntranceSigningKey $key): array => [
                'kid' => $key->kid,
                'alg' => 'Ed25519',
                'public_key' => $key->public_key,
                'expires_at' => $key->expires_at->toIso8601String(),
            ])
            ->all();

        return response()
            ->json(['keys' => $keys])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * The revoked / already-checked-in delta since a cursor.
     *
     * `next` is what the device stores and sends back; when it equals the
     * `since` it sent, nothing changed. The cursor is a sequence rather than a
     * timestamp precisely so a scanner with a wrong clock cannot skip rows.
     *
     * `poll_seconds` and `stale_after_seconds` travel with the payload so the
     * cadence is server-controlled: a big event can tighten the window without
     * re-deploying every device.
     *
     * A `token_superseded` entry's `payload.hash` is what makes §5's
     * enforcement work offline at all: a device remembers the newest hash
     * per admission id and refuses a scan whose token hashes to something
     * else. A device that has never seen an admission for this event has
     * no hash on file and therefore accepts — failing open offline is
     * deliberate. A freshly provisioned scanner, or one an operator just
     * reset, must not turn away an entire queue because its local cache is
     * empty; the delta feed only ever tells it about tokens rotated after
     * the device started asking. Online verification has no such gap — it
     * always reads `admissions.token_nonce_hash` straight from the
     * database (`VerifyAdmissionToken::isSuperseded()`) and is exact, so
     * this asymmetry is bounded to the door, never the server's own
     * decision.
     */
    public function delta(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $checkpoint = $this->checkpointFor($device);

        $validated = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
        ]);

        $since = (int) ($validated['since'] ?? 0);

        $entries = EntranceDeltaEntry::withoutBrandScope()
            ->where('brand_id', $device->brand_id)
            ->where('event_id', $checkpoint->event_id)
            ->where('sequence', '>', $since)
            ->orderBy('sequence')
            ->limit(5000)
            ->get();

        $profile = EntranceEventProfile::withoutBrandScope()
            ->where('brand_id', $device->brand_id)
            ->where('event_id', $checkpoint->event_id)
            ->first();

        return response()->json([
            'since' => $since,
            'next' => $entries->max('sequence') ?? $since,
            'entries' => $entries->map(fn (EntranceDeltaEntry $entry): array => [
                'admission_id' => $entry->admission_id,
                'kind' => $entry->kind,
                'payload' => $entry->payload,
            ])->all(),
            // Everything below is what the device needs to decide offline.
            'reentry_policy' => $profile === null
                ? ReentryPolicy::Unlimited->value
                : $profile->reentry_policy->value,
            'days' => $profile === null ? [] : ($profile->days ?? []),
            'poll_seconds' => $profile === null
                ? EntranceEventProfile::DEFAULT_POLL_SECONDS
                : $profile->pollSeconds(),
            'stale_after_seconds' => $profile === null
                ? EntranceEventProfile::DEFAULT_STALE_AFTER_SECONDS
                : $profile->staleAfterSeconds(),
        ]);
    }

    /**
     * A buffered batch of scans. Idempotent per `client_scan_id`, because a
     * device that reconnects mid-upload and resends is behaving correctly.
     */
    public function scans(Request $request, SynchronizeScanBatch $synchronize): JsonResponse
    {
        $device = $this->device($request);
        $checkpoint = $this->checkpointFor($device);

        $validated = $request->validate([
            'scans' => ['required', 'array', 'max:500'],
            'scans.*.token' => ['required', 'string', 'max:2048'],
            'scans.*.client_scan_id' => ['required', 'string', 'max:64'],
            'scans.*.scanned_at' => ['nullable', 'date'],
            'scans.*.direction' => ['nullable', Rule::in(['in', 'out'])],
        ]);

        /** @var list<array{token: string, client_scan_id: string, scanned_at?: string|null, direction?: string|null}> $scans */
        $scans = $validated['scans'];

        return response()->json($synchronize->handle($device, $checkpoint, $scans));
    }

    private function device(Request $request): EntranceDevice
    {
        /** @var EntranceDevice $device */
        $device = $request->attributes->get(EnsureEntranceDevice::ATTRIBUTE);

        return $device;
    }

    private function checkpointFor(EntranceDevice $device): Checkpoint
    {
        /** @var Checkpoint $checkpoint */
        $checkpoint = Checkpoint::withoutBrandScope()
            ->where('brand_id', $device->brand_id)
            ->whereKey($device->checkpoint_id)
            ->firstOrFail();

        return $checkpoint;
    }
}
