<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RecordScan;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Event\Models\Event;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * The door: badge artwork, the checkpoints and scanners behind the entrance
 * client, the brand's signing key, and a floor's worth of real check-ins.
 *
 * Admissions themselves are never created here — they are issued by
 * Registration's confirmation, which is the only way they are ever created
 * in production. This slice adds what the entrance side owns on top.
 *
 * Check-ins go through `RecordScan` with genuinely signed tokens, so the
 * seeded rows carry real accept/reject reasons rather than invented ones —
 * and no clock is moved to get them. A token is only valid inside its signing
 * key's window and a scan only counts on one of the event's days (D-ADM-04),
 * so the entrance log is seeded for the edition that is running today and for
 * no other. The event slice is what makes sure one always is.
 */
final class DemoAdmissionSeeder
{
    /** Roughly half the floor has come through the door by mid-morning. */
    private const float SCANNED_SHARE = 0.5;

    public function __construct(
        private readonly IssueAdmissionToken $issueToken,
        private readonly RecordScan $recordScan,
        private readonly RotateEntranceSigningKey $rotateKey,
        private readonly IssueAdmissionClaimLink $issueClaimLink,
        private readonly MediaLibrary $library,
    ) {}

    /**
     * The brand-wide half: one active signing key, which every entrance
     * device verifies tokens against.
     */
    public function seedSigningKey(Brand $brand): void
    {
        $active = EntranceSigningKey::query()->whereNull('retired_at')->exists();

        if ($active) {
            return;
        }

        $this->rotateKey->handle($brand->getKey());
    }

    /**
     * @param  array<string, MediaItem>  $media
     */
    public function handle(Event $event, array $media): void
    {
        $this->seedBadgeTemplate($event, $media);

        $checkpoints = $this->seedCheckpoints($event);

        $this->seedDevices($checkpoints);
        $this->seedCheckIns($event, $checkpoints);
        $this->seedClaimLinks($event);
    }

    /**
     * The event's fallback badge template (D-ADM-05), pointing at the brand's
     * media by ULID and never a path (ADR-0035). This is what puts genuine
     * usage references in the library — a seeded badge PDF renders with the
     * artwork, and the console refuses to delete a file the badge needs.
     *
     * @param  array<string, MediaItem>  $media
     */
    private function seedBadgeTemplate(Event $event, array $media): void
    {
        $alreadySeeded = BadgeTemplate::query()
            ->where('event_id', $event->getKey())
            ->whereNull('role')
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $template = BadgeTemplate::factory()
            ->forEvent($event->getKey())
            ->withLayout(new BadgeLayoutData(
                logoMediaId: ($media['logo'] ?? null)?->getKey(),
                backgroundMediaId: ($media['badge-background'] ?? null)?->getKey(),
                footerText: $event->name,
            ))
            ->create(['name' => "{$event->name} badge"]);

        foreach ($template->layout->mediaIds() as $mediaId) {
            $this->library->recordUsage($mediaId, 'admission', 'badge_template', $template->getKey());
        }
    }

    /**
     * @return list<Checkpoint>
     */
    private function seedCheckpoints(Event $event): array
    {
        $checkpoints = [];

        foreach (['Main entrance', 'Crew door'] as $name) {
            $checkpoints[] = Checkpoint::query()
                ->where('event_id', $event->getKey())
                ->firstWhere('name', $name)
                ?? Checkpoint::factory()->forEvent($event->getKey())->create(['name' => $name]);
        }

        return $checkpoints;
    }

    /**
     * Two scanners on the main door and a revoked one, so the device list
     * shows enrolment and revocation rather than a single happy row. The
     * enrolment plaintext is intentionally dropped: production shows it once
     * and stores only the hash, and a demo that kept it would teach the wrong
     * thing.
     *
     * @param  list<Checkpoint>  $checkpoints
     */
    private function seedDevices(array $checkpoints): void
    {
        foreach ($checkpoints as $index => $checkpoint) {
            if (EntranceDevice::query()->where('checkpoint_id', $checkpoint->getKey())->exists()) {
                continue;
            }

            EntranceDevice::factory()->create([
                'checkpoint_id' => $checkpoint->getKey(),
                'label' => 'Scanner '.$this->deviceLetter($index),
                'last_seen_at' => now()->subMinutes(3),
            ]);

            if ($index !== 0) {
                continue;
            }

            EntranceDevice::factory()->revoked()->create([
                'checkpoint_id' => $checkpoint->getKey(),
                'label' => 'Lost tablet',
            ]);
        }
    }

    /**
     * Scanner labels run A, B, C… and wrap at `Z` rather than walking off the
     * end of the alphabet, so a longer checkpoint list still names its devices
     * with letters.
     */
    private function deviceLetter(int $index): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return $alphabet[$index % strlen($alphabet)];
    }

    /**
     * @param  list<Checkpoint>  $checkpoints
     */
    private function seedCheckIns(Event $event, array $checkpoints): void
    {
        $admissionsOfEvent = Admission::query()->where('event_id', $event->getKey());

        // Check-ins carry no event id — a scan belongs to an admission, and a
        // rejected one may not resolve to any (see the migration). So the
        // "already seeded" question is asked of this event's admissions.
        $alreadyScanned = CheckIn::query()
            ->whereIn('admission_id', $admissionsOfEvent->clone()->select('id'))
            ->exists();

        if ($alreadyScanned) {
            return;
        }

        // Only an event that is open right now can be scanned honestly. A
        // token is signed by a key with its own validity window, and a scan
        // only counts on one of the event's days (D-ADM-04) — so for an
        // edition that has not started, or one that ended before the current
        // signing key existed, an empty entrance log is the truthful state.
        if (! $this->doorsAreOpen($event)) {
            return;
        }

        $admissions = $admissionsOfEvent->get();

        if ($admissions->isEmpty() || $checkpoints === []) {
            return;
        }

        $this->scan($event, $admissions->take((int) ceil($admissions->count() * self::SCANNED_SHARE)), $checkpoints);
    }

    /**
     * Whether today falls inside the event's projected day calendar —
     * Admission's own read model of it (ADR-0033), asked exactly the way
     * `RecordScan` asks it, so this predicate and the rejection rule cannot
     * drift apart.
     */
    private function doorsAreOpen(Event $event): bool
    {
        $profile = EntranceEventProfile::query()
            ->where('event_id', $event->getKey())
            ->first();

        return $profile?->dayCovering(now()) !== null;
    }

    /**
     * @param  Collection<int, Admission>  $admissions
     * @param  list<Checkpoint>  $checkpoints
     */
    private function scan(Event $event, Collection $admissions, array $checkpoints): void
    {
        $admissions->values()->each(function (Admission $admission, int $index) use ($event, $checkpoints): void {
            $checkpoint = $checkpoints[$index % count($checkpoints)];
            $device = EntranceDevice::query()
                ->where('checkpoint_id', $checkpoint->getKey())
                ->whereNull('revoked_at')
                ->first();

            $token = $this->issueToken->handle($admission);

            $this->recordScan->handle(
                token: $token,
                brandId: $admission->brand_id,
                eventId: $event->getKey(),
                clientScanId: (string) Str::uuid(),
                scannedAt: now()->subMinutes($index * 2),
                checkpoint: $checkpoint,
                device: $device,
            );

            // Every seventh person tries a scanner at the wrong door with a
            // mangled token: the rejection reasons are half of what an
            // entrance log is for.
            if ($index % 7 === 6) {
                $this->recordScan->handle(
                    token: $token.'tampered',
                    brandId: $admission->brand_id,
                    eventId: $event->getKey(),
                    clientScanId: (string) Str::uuid(),
                    scannedAt: now()->subMinutes($index * 2),
                    checkpoint: $checkpoint,
                    device: $device,
                );
            }
        });
    }

    /**
     * Claim links (ADR-0032): the badge a registrant bought for someone who
     * has no account yet. Only holder-less admissions can be claimed, which
     * is what the query filters on — the action refuses the rest outright.
     */
    private function seedClaimLinks(Event $event): void
    {
        $unclaimed = Admission::query()
            ->where('event_id', $event->getKey())
            ->whereNull('holder_user_id')
            ->whereIn('state', [AdmissionState::Issued, AdmissionState::Assigned])
            ->whereNotIn('id', AdmissionClaimLink::query()->select('admission_id'))
            ->limit(2)
            ->get();

        foreach ($unclaimed as $admission) {
            $this->issueClaimLink->handle($admission, intendedEmail: fake()->safeEmail());
        }
    }
}
