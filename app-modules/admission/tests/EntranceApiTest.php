<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\CheckInBatchSynchronized;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EntranceSigningKey;

/**
 * The entrance API and its device credential (ADR-0033).
 *
 * `entranceTestLane()` and `entranceTestHeaders()` moved to this module's
 * `tests/Helpers.php` when `EntryConditionsAtTheDoorTest` became a second
 * caller: under `--parallel` each worker loads only the files it was handed,
 * so a helper declared in one test file does not exist in the worker running
 * another (.ai/rules/tests.md).
 */
it('rejects a request with no bearer token', function (): void {
    $this->withBrand();

    $this->getJson('/api/entrance/signing-keys')->assertStatus(401);
});

it('rejects a revoked device with the same 401 as an unknown one', function (): void {
    $this->withBrand();

    $plain = Str::random(48);
    EntranceDevice::factory()->withToken($plain)->revoked()->create();

    // Telling the finder of a lost scanner "this existed but was revoked" is
    // information they have no business getting.
    $this->getJson('/api/entrance/signing-keys', entranceTestHeaders($plain))->assertStatus(401);
    $this->getJson('/api/entrance/signing-keys', entranceTestHeaders(Str::random(48)))->assertStatus(401);
});

it('authenticates a device and stamps last_seen_at', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();

    expect($lane['device']->last_seen_at)->toBeNull();

    $this->getJson('/api/entrance/signing-keys', entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonStructure(['keys' => [['kid', 'alg', 'public_key', 'expires_at']]]);

    expect($lane['device']->fresh()->last_seen_at)->not->toBeNull();
});

it('publishes retired-but-unexpired keys and withholds expired ones', function (): void {
    $brand = $this->withBrand();

    $active = EntranceSigningKey::factory()->create();
    $retired = EntranceSigningKey::factory()->retired()->create();
    $expired = EntranceSigningKey::factory()->expired()->create();

    $lane = entranceTestLane();

    $keys = collect($this->getJson('/api/entrance/signing-keys', entranceTestHeaders($lane['token']))
        ->assertOk()
        ->json('keys'))->pluck('kid');

    expect($keys)->toContain($active->kid, $retired->kid)
        ->and($keys)->not->toContain($expired->kid);
});

it('serves a PII-free delta with a monotonic cursor and the offline decision inputs', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();
    $dayId = (string) Str::ulid();

    EntranceEventProfile::factory()
        ->policy(ReentryPolicy::OncePerDay)
        ->withDays([['day_id' => $dayId, 'date' => now()->format('Y-m-d'), 'sequence' => 1, 'label' => 'Day 1']])
        ->create(['event_id' => $lane['eventId']]);

    $admission = Admission::factory()->create([
        'event_id' => $lane['eventId'],
        'holder_user_id' => (string) Str::ulid(),
    ]);
    $token = app(IssueAdmissionToken::class)->current($admission);

    $this->postJson('/api/entrance/scans', [
        'scans' => [['token' => $token, 'client_scan_id' => (string) Str::uuid()]],
    ], entranceTestHeaders($lane['token']))->assertOk();

    $response = $this->getJson('/api/entrance/delta?since=0', entranceTestHeaders($lane['token']))->assertOk();

    expect($response->json('entries'))->toHaveCount(1)
        ->and($response->json('entries.0.admission_id'))->toBe($admission->id)
        ->and($response->json('entries.0.kind'))->toBe('checked_in')
        ->and($response->json('next'))->toBeGreaterThan(0)
        ->and($response->json('reentry_policy'))->toBe('once_per_day')
        ->and($response->json('poll_seconds'))->toBe(EntranceEventProfile::DEFAULT_POLL_SECONDS)
        ->and($response->json('stale_after_seconds'))->toBe(EntranceEventProfile::DEFAULT_STALE_AFTER_SECONDS);

    // ADR-0033's central guarantee: a stolen scanner leaks nothing. The delta
    // carries opaque IDs, never anything that identifies a person.
    $body = $response->getContent();
    expect($body)->not->toContain($admission->holder_user_id)
        ->and($body)->not->toContain($admission->registration_id);

    // The cursor is a resume point, not a re-download.
    $next = $response->json('next');
    expect($this->getJson('/api/entrance/delta?since='.$next, entranceTestHeaders($lane['token']))
        ->assertOk()
        ->json('entries'))->toBe([]);
});

it('records an accepted scan and emits AdmissionCheckedIn carrying registration_id', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();
    $admission = Admission::factory()->create(['event_id' => $lane['eventId']]);
    $token = app(IssueAdmissionToken::class)->current($admission);

    Event::fake([AdmissionCheckedIn::class, CheckInBatchSynchronized::class]);

    $this->postJson('/api/entrance/scans', [
        'scans' => [['token' => $token, 'client_scan_id' => 'scan-1']],
    ], entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('results.0.result', 'accepted');

    // ADR-0037 blocks refunds on a checked-in admission, and Commerce answers
    // that from this payload alone — never by reading Admission's tables.
    Event::assertDispatched(
        AdmissionCheckedIn::class,
        fn (AdmissionCheckedIn $event): bool => $event->admissionId === $admission->id
            && $event->registrationId === $admission->registration_id
            && $event->eventId === $lane['eventId'],
    );

    Event::assertDispatched(CheckInBatchSynchronized::class);
});

it('is idempotent per client_scan_id so a reconnect replay writes once', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();
    $admission = Admission::factory()->create(['event_id' => $lane['eventId']]);
    $token = app(IssueAdmissionToken::class)->current($admission);

    $payload = ['scans' => [['token' => $token, 'client_scan_id' => 'replayed-scan']]];

    $this->postJson('/api/entrance/scans', $payload, entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('duplicates', 0);

    // A device reconnecting mid-upload and resending is correct behaviour,
    // not a fault — so it is counted, not rejected.
    $this->postJson('/api/entrance/scans', $payload, entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('duplicates', 1);

    expect(CheckIn::query()->where('admission_id', $admission->id)->count())->toBe(1);
});

it('rejects a forged token and still records it in the audit trail', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();

    $this->postJson('/api/entrance/scans', [
        'scans' => [['token' => 'KVX1.nope.body.sig', 'client_scan_id' => 'bad-1']],
    ], entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('rejected', 1)
        ->assertJsonPath('results.0.reason_code', CheckInRejectionReason::InvalidSignature->value);

    // A rejection is a row, not a discarded error.
    $checkIn = CheckIn::query()->where('client_scan_id', 'bad-1')->firstOrFail();

    expect($checkIn->result)->toBe(CheckInResult::Rejected)
        ->and($checkIn->admission_id)->toBeNull()
        ->and($checkIn->device_id)->toBe($lane['device']->id);
});

it('rejects a token minted for a different event', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $lane = entranceTestLane();
    $elsewhere = Admission::factory()->create(['event_id' => (string) Str::ulid()]);
    $token = app(IssueAdmissionToken::class)->current($elsewhere);

    $this->postJson('/api/entrance/scans', [
        'scans' => [['token' => $token, 'client_scan_id' => 'wrong-event']],
    ], entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('results.0.reason_code', CheckInRejectionReason::WrongEvent->value);
});
