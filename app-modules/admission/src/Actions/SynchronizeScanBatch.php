<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\Date;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Events\CheckInBatchSynchronized;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;

/**
 * Takes the buffer a device delivers when its uplink returns (ADR-0033) and
 * records each scan, reporting per-scan outcomes so the device can reconcile
 * what it provisionally admitted against what the server decided.
 *
 * Scans are processed one at a time rather than in one transaction: a batch
 * is a set of independent decisions, and one malformed token must not roll
 * back the fifty good scans delivered with it.
 *
 * Duplicates are counted, not rejected. A device that reconnects mid-upload
 * and resends is behaving correctly, and `RecordScan` is idempotent per
 * `client_scan_id`, so the honest report is "I already had these".
 */
final class SynchronizeScanBatch
{
    public function __construct(
        private readonly RecordScan $recordScan,
    ) {}

    /**
     * @param  list<array{token: string, client_scan_id: string, scanned_at?: string|null, direction?: string|null}>  $scans
     * @return array{results: list<array{client_scan_id: string, result: string, reason_code: string|null, duplicate: bool}>, accepted: int, rejected: int, duplicates: int}
     */
    public function handle(EntranceDevice $device, Checkpoint $checkpoint, array $scans): array
    {
        $results = [];
        $accepted = 0;
        $rejected = 0;
        $duplicates = 0;

        foreach ($scans as $scan) {
            $alreadyRecorded = CheckIn::withoutBrandScope()
                ->where('brand_id', $device->brand_id)
                ->where('client_scan_id', $scan['client_scan_id'])
                ->exists();

            $checkIn = $this->recordScan->handle(
                token: $scan['token'],
                brandId: $device->brand_id,
                eventId: $checkpoint->event_id,
                clientScanId: $scan['client_scan_id'],
                // Date facade, not Carbon::parse: the app runs on
                // CarbonImmutable and the facade honours that binding.
                scannedAt: isset($scan['scanned_at'])
                    ? Date::parse($scan['scanned_at'])
                    : null,
                checkpoint: $checkpoint,
                device: $device,
                direction: $scan['direction'] ?? 'in',
            );

            if ($alreadyRecorded) {
                $duplicates++;
            } elseif ($checkIn->result === CheckInResult::Accepted) {
                $accepted++;
            } else {
                $rejected++;
            }

            $results[] = [
                'client_scan_id' => $checkIn->client_scan_id,
                'result' => $checkIn->result->value,
                'reason_code' => $checkIn->reason_code,
                'duplicate' => $alreadyRecorded,
            ];
        }

        event(new CheckInBatchSynchronized(
            deviceId: $device->id,
            eventId: $checkpoint->event_id,
            brandId: $device->brand_id,
            accepted: $accepted,
            rejected: $rejected,
            duplicates: $duplicates,
            synchronizedAt: now()->toIso8601String(),
        ));

        return [
            'results' => $results,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'duplicates' => $duplicates,
        ];
    }
}
