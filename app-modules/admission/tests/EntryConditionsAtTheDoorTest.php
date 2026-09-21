<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Registration\Events\RegistrationConfirmed;

/**
 * The door half of entry conditions (ADR-0066 §6.4, §9.3; D-ADM-06/07).
 *
 * What this module knows is four scalars per condition, handed over on
 * `RegistrationConfirmed` and never looked up again: Admission reads no
 * Registration table, learns of no assertion, and would not know what one
 * is (ADR-0004, `RegistrationCommerceImportBoundaryTest`).
 *
 * The three properties worth pinning: the list is **stamped** at issuance so
 * a reworded condition cannot rewrite a ticket already in somebody's pocket;
 * the **PII-free** delta feed carries the label and stays PII-free (ADR-0002,
 * ADR-0033, spec §11); and the **badge carries none of it** (D-ADM-07).
 *
 * Helper names prefixed `doorCondition*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function doorConditionConfirmation(array $conditions, int $quantity = 1): RegistrationConfirmed
{
    return new RegistrationConfirmed(
        registrationId: (string) Str::ulid(),
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: app(BrandContext::class)->currentOrFail()->getKey(),
        registrantUserId: (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: [
            ['kind' => 'admission', 'role' => 'visitor', 'quantity' => $quantity, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
        ],
        entryConditionKeys: $conditions,
    );
}

function doorConditionStudent(string $verification = 'document_at_door'): array
{
    return [
        'key' => 'student',
        'label' => 'Student ticket',
        'verification' => $verification,
        'document_hint' => 'Bring a valid student ID',
    ];
}

it('stamps the confirmed conditions on every admission it issues', function (): void {
    $this->withBrand();

    $confirmation = doorConditionConfirmation([doorConditionStudent()], quantity: 2);

    event($confirmation);

    $admissions = Admission::where('registration_id', $confirmation->registrationId)->get();

    expect($admissions)->toHaveCount(2)
        ->and($admissions->first()->entry_conditions)->toBe([doorConditionStudent()]);
});

it('stamps null where the confirmation named no condition', function (): void {
    $this->withBrand();

    $confirmation = doorConditionConfirmation([]);

    event($confirmation);

    expect(Admission::where('registration_id', $confirmation->registrationId)->value('entry_conditions'))->toBeNull();
});

it('serves the document check in the entrance payload, and only for a door condition', function (): void {
    $brand = $this->withBrand();

    $confirmation = doorConditionConfirmation([
        doorConditionStudent(),
        // An operator-checked condition fills a console worklist; a door has
        // nothing to do with it and never hears about it (D-REG-22).
        ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'document_hint' => null],
    ]);

    event($confirmation);

    $admission = Admission::where('registration_id', $confirmation->registrationId)->sole();

    $lane = entranceTestLane($confirmation->eventId);

    $entries = collect($this->getJson('/api/entrance/delta?since=0', entranceTestHeaders($lane['token']))
        ->assertOk()
        ->json('entries'))
        ->where('kind', EntranceDeltaEntry::KIND_ENTRY_CONDITIONS);

    expect($entries)->toHaveCount(1)
        ->and($entries->first()['admission_id'])->toBe($admission->id)
        ->and($entries->first()['payload']['conditions'])->toBe([[
            'key' => 'student',
            'label' => 'Student ticket',
            'document_hint' => 'Bring a valid student ID',
        ]]);

    // Still PII-free (ADR-0033, spec §11): a label beside an opaque ULID.
    expect(json_encode($entries->first()))
        ->not->toContain($brand->getKey())
        ->not->toContain($confirmation->registrantUserId);
});

it('carries the outcome of a door check on AdmissionCheckedIn, and replays it as a no-op', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $confirmation = doorConditionConfirmation([doorConditionStudent()]);
    event($confirmation);

    $admission = Admission::where('registration_id', $confirmation->registrationId)->sole();
    $lane = entranceTestLane($confirmation->eventId);
    $token = app(IssueAdmissionToken::class)->current($admission);

    Event::fake([AdmissionCheckedIn::class]);

    $payload = ['scans' => [[
        'token' => $token,
        'client_scan_id' => 'door-check-1',
        'conditions_checked' => [['key' => 'student', 'outcome' => 'refused']],
    ]]];

    $this->postJson('/api/entrance/scans', $payload, entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('accepted', 1);

    Event::assertDispatched(AdmissionCheckedIn::class, fn (AdmissionCheckedIn $event): bool => $event->conditionsChecked === [['key' => 'student', 'outcome' => 'refused']]);

    // The offline client uploads its buffer again after a dropped uplink.
    // One check-in, one event — the outcome is not recorded twice.
    $this->postJson('/api/entrance/scans', $payload, entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('duplicates', 1);

    Event::assertDispatchedTimes(AdmissionCheckedIn::class, 1);

    expect(CheckIn::where('admission_id', $admission->id)->count())->toBe(1);

    // A refusal at the door never revokes the admission (T-8, ADR-0005).
    expect($admission->fresh()->state->value)->toBe('issued');
});

it('refuses an outcome that is neither confirmed nor refused', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $confirmation = doorConditionConfirmation([doorConditionStudent()]);
    event($confirmation);

    $admission = Admission::where('registration_id', $confirmation->registrationId)->sole();
    $lane = entranceTestLane($confirmation->eventId);

    $this->postJson('/api/entrance/scans', ['scans' => [[
        'token' => app(IssueAdmissionToken::class)->current($admission),
        'client_scan_id' => 'door-check-bad',
        'conditions_checked' => [['key' => 'student', 'outcome' => 'maybe']],
    ]]], entranceTestHeaders($lane['token']))
        ->assertStatus(422);
});

it('prints the condition and its hint on the ticket, and renders a stamped admission', function (): void {

    $html = view('admission::tickets.document', ['pages' => [ticketPageFixture([
        'entryConditions' => [['label' => 'Student ticket', 'documentHint' => 'Bring a valid student ID']],
    ])]])->render();

    expect($html)->toContain('Student ticket')
        ->and($html)->toContain('Bring a valid student ID');

    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $confirmation = doorConditionConfirmation([doorConditionStudent()]);
    event($confirmation);

    $admission = Admission::where('registration_id', $confirmation->registrationId)->sole();

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('keeps every entry condition off the badge', function (): void {
    // D-ADM-07, structural rather than by rendering: the decision is that
    // `BadgeDocumentRenderer` and the badge templates are untouched, and a
    // grep says so for every future template as well.
    $sources = [
        file_get_contents(base_path('app-modules/admission/src/Documents/BadgeDocumentRenderer.php')),
        ...array_map(file_get_contents(...), glob(base_path('app-modules/admission/resources/views/badges/*.blade.php')) ?: []),
    ];

    foreach ($sources as $source) {
        expect($source)->not->toContain('entry_conditions')
            ->and($source)->not->toContain('entryConditions');
    }
});
