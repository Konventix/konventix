<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RecordScan;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Event\Enums\ReentryPolicy as EventReentryPolicy;

/**
 * Day-scoped validity (D-ADM-04) and the event's re-entry knob (D-ADM-03).
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 *
 * @return array{admission: Admission, eventId: string, dayId: string, profile: EntranceEventProfile}
 */
function dayTestLane(ReentryPolicy $policy = ReentryPolicy::Unlimited, int $dayCount = 1): array
{
    $eventId = (string) Str::ulid();
    $days = [];

    for ($i = 0; $i < $dayCount; $i++) {
        $days[] = [
            'day_id' => (string) Str::ulid(),
            'date' => now()->addDays($i)->format('Y-m-d'),
            'sequence' => $i + 1,
            'label' => 'Day '.($i + 1),
        ];
    }

    $profile = EntranceEventProfile::factory()
        ->policy($policy)
        ->withDays($days)
        ->create(['event_id' => $eventId]);

    return [
        'admission' => Admission::factory()->create(['event_id' => $eventId]),
        'eventId' => $eventId,
        'dayId' => $days[0]['day_id'],
        'profile' => $profile,
    ];
}

function dayTestScan(string $token, string $brandId, string $eventId, string $scanId, ?string $direction = 'in')
{
    return app(RecordScan::class)->handle(
        token: $token,
        brandId: $brandId,
        eventId: $eventId,
        clientScanId: $scanId,
        direction: $direction ?? 'in',
    );
}

it('keeps the Admission and Event re-entry vocabularies value-compatible', function (): void {
    // The enum is deliberately re-declared rather than imported (ADR-0004),
    // so nothing but a test stops the two drifting apart.
    $mine = array_map(fn (ReentryPolicy $c): string => $c->value, ReentryPolicy::cases());
    $theirs = array_map(fn (EventReentryPolicy $c): string => $c->value, EventReentryPolicy::cases());

    expect($mine)->toEqualCanonicalizing($theirs);
});

it('admits repeatedly under the unlimited default', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane(ReentryPolicy::Unlimited);
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 's1')->result)->toBe(CheckInResult::Accepted)
        ->and(dayTestScan($token, $brand->id, $lane['eventId'], 's2')->result)->toBe(CheckInResult::Accepted);
});

it('admits once only under once_only', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane(ReentryPolicy::OnceOnly);
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 's1')->result)->toBe(CheckInResult::Accepted);

    $second = dayTestScan($token, $brand->id, $lane['eventId'], 's2');

    expect($second->result)->toBe(CheckInResult::Rejected)
        ->and($second->reason_code)->toBe(CheckInRejectionReason::ReentryNotPermitted->value);
});

it('admits once per day under once_per_day', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane(ReentryPolicy::OncePerDay, dayCount: 2);
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 'd1-a')->result)->toBe(CheckInResult::Accepted)
        ->and(dayTestScan($token, $brand->id, $lane['eventId'], 'd1-b')->result)->toBe(CheckInResult::Rejected);

    // Same admission, next day: a fresh allowance.
    $this->travel(1)->day();

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 'd2-a')->result)->toBe(CheckInResult::Accepted);
});

it('lets an exit through regardless of the re-entry policy', function (): void {
    // Re-entry limits what may come *in*; nobody is trapped inside.
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane(ReentryPolicy::OnceOnly);
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    dayTestScan($token, $brand->id, $lane['eventId'], 'in-1');

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 'out-1', 'out')->result)
        ->toBe(CheckInResult::Accepted);
});

it('rejects a scan that falls outside every event day', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane();
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    // An admission is valid *on a day* (D-ADM-04); a scan a week later has no
    // day to be valid on.
    $this->travel(7)->days();

    $result = dayTestScan($token, $brand->id, $lane['eventId'], 'late');

    expect($result->result)->toBe(CheckInResult::Rejected)
        ->and($result->reason_code)->toBe(CheckInRejectionReason::OutsideEventDay->value);
});

it('rejects a day-scoped token on a day it does not cover', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane(dayCount: 2);
    $days = $lane['profile']->days;

    // Scoped to day two only.
    $token = app(IssueAdmissionToken::class)->handle($lane['admission'], [$days[1]['day_id']]);

    $onDayOne = dayTestScan($token, $brand->id, $lane['eventId'], 'wrong-day');

    expect($onDayOne->result)->toBe(CheckInResult::Rejected)
        ->and($onDayOne->reason_code)->toBe(CheckInRejectionReason::NotValidOnThisDay->value);

    $this->travel(1)->day();

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 'right-day')->result)
        ->toBe(CheckInResult::Accepted);
});

it('stamps the event day on an accepted check-in', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane();
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    expect(dayTestScan($token, $brand->id, $lane['eventId'], 'stamped')->event_day_id)
        ->toBe($lane['dayId']);
});

it('rejects a revoked admission at the door', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = dayTestLane();
    $token = app(IssueAdmissionToken::class)->handle($lane['admission']);

    $lane['admission']->forceFill(['state' => AdmissionState::Revoked])->save();

    $result = dayTestScan($token, $brand->id, $lane['eventId'], 'revoked-scan');

    expect($result->result)->toBe(CheckInResult::Rejected)
        ->and($result->reason_code)->toBe(CheckInRejectionReason::AdmissionNotValid->value);
});
