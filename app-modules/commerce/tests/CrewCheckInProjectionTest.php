<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Models\CheckedInAdmission;

/**
 * #333: a crew badge (D-STF-01) has no registration behind it, so
 * `AdmissionCheckedIn` carries a null `registrationId`. Nobody bought the
 * badge, so there is no refund for its check-in to block (ADR-0037), and
 * Commerce's projection skips it. Before #333 the event could not even be
 * built for one.
 */
it('projects nothing for a crew badge, which has no registration behind it', function (): void {
    $brand = $this->withBrand();

    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: (string) Str::ulid(),
        registrationId: null,
        eventId: (string) Str::ulid(),
        brandId: $brand->id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    // Derivation: the listener returns before projecting on a null
    // registration id, so the read model stays empty.
    expect(CheckedInAdmission::query()->count())->toBe(0);
});
