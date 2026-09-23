<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Gdpr\AdmissionGdprHandler;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * The email branch ADR-0050 §3 owes Admission: an invitee who never claimed
 * a link has no account at all, only the `intended_email` the link was
 * issued to (ADR-0032, slice 20 fix-wave I1a).
 */
it('exports the claim links bound to an email-only subject', function (): void {
    $this->withBrand();

    $link = AdmissionClaimLink::factory()->boundTo('invitee@example.de')->create();

    // Neither belongs to the subject: a different address, and a link
    // already claimed by an account.
    AdmissionClaimLink::factory()->boundTo('someone-else@example.de')->create();
    AdmissionClaimLink::factory()->claimed((string) Str::ulid())->create();

    $export = (new AdmissionGdprHandler)->exportFor(SubjectRef::forEmail('invitee@example.de'));

    expect($export['claim_links'])->toHaveCount(1)
        ->and($export['claim_links'][0]['admission_id'])->toBe($link->admission_id);
});

it('scrubs the intended_email of a claim link bound to an email-only subject', function (): void {
    $this->withBrand();

    $link = AdmissionClaimLink::factory()->boundTo('invitee@example.de')->create();
    $other = AdmissionClaimLink::factory()->boundTo('someone-else@example.de')->create();

    (new AdmissionGdprHandler)->delete(SubjectRef::forEmail('invitee@example.de'), DeletionMode::Anonymize);

    expect($link->refresh()->intended_email)->toBeNull()
        ->and($other->refresh()->intended_email)->toBe('someone-else@example.de');
});

it('no-ops for an email with no claim link', function (): void {
    $this->withBrand();

    $export = (new AdmissionGdprHandler)->exportFor(SubjectRef::forEmail('nobody@example.de'));

    expect($export['claim_links'])->toBe([]);
});

// #292 §10: the manager's side of the rows, and the claim a person made.
it('exports the tickets a person bought for others, and forgets who claimed a link on erasure', function (): void {
    $this->withBrand();
    $subjectId = (string) Str::ulid();
    $other = (string) Str::ulid();

    $held = Admission::factory()->create(['holder_user_id' => $subjectId, 'buyer_user_id' => $subjectId]);
    $managed = Admission::factory()->forRegistration((string) Str::ulid())->create(['holder_user_id' => $other, 'buyer_user_id' => $subjectId]);
    $open = Admission::factory()->forRegistration((string) Str::ulid())->create(['holder_user_id' => null, 'buyer_user_id' => $subjectId]);
    Admission::factory()->create(['holder_user_id' => $other, 'buyer_user_id' => $other]);
    $link = AdmissionClaimLink::factory()->create(['admission_id' => $held->id, 'claimed_by_user_id' => $subjectId, 'claimed_at' => now()]);

    $export = (new AdmissionGdprHandler)->exportFor(SubjectRef::forUser($subjectId));

    expect(array_column($export['admissions'], 'id'))->toBe([$held->id])
        ->and(array_column($export['managed_admissions'], 'id'))->toEqualCanonicalizing([$managed->id, $open->id]);

    (new AdmissionGdprHandler)->delete(SubjectRef::forUser($subjectId), DeletionMode::Anonymize);

    expect($link->fresh()->claimed_by_user_id)->toBeNull()
        ->and($link->fresh()->claimed_at)->not->toBeNull();
});

// D-HUB-01: the presence row is attendance data this module collected
// about the person and holds itself, so it is exported — and deleted in
// both modes, since a presence without a person means nothing.
it('exports and erases presence rows', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()->create(['event_id' => $eventId]);
    $holder = ticketPerson();
    event(presenceScan(Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $holder->id])));

    $handler = new AdmissionGdprHandler;
    $export = $handler->exportFor(SubjectRef::forUser($holder->id));
    expect($export['event_presence'])->toHaveCount(1)
        ->and($export['event_presence'][0]['event_id'])->toBe($eventId);

    $handler->delete(SubjectRef::forUser($holder->id), DeletionMode::Anonymize);
    expect(EventPresence::query()->where('user_id', $holder->id)->count())->toBe(0);
});

it('erases presence rows on a full erasure too', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()->create(['event_id' => $eventId]);
    $holder = ticketPerson();
    $other = ticketPerson();
    event(presenceScan(Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $holder->id])));
    event(presenceScan(Admission::factory()->create(['event_id' => $eventId, 'holder_user_id' => $other->id])));

    (new AdmissionGdprHandler)->delete(SubjectRef::forUser($holder->id), DeletionMode::Erase);

    expect(EventPresence::query()->where('user_id', $holder->id)->count())->toBe(0)
        ->and(EventPresence::query()->where('user_id', $other->id)->count())->toBe(1);
});

// #329 §6: `check_ins.actor_user_id` is the admin who checked a guest in
// from the console. It is exported with the admin's data and forgotten on
// erasure in both modes, while the row — the guest's attendance — stays.
it('exports the console check-ins an admin wrote, and nulls the actor on erasure in both modes', function (DeletionMode $mode): void {
    $this->withBrand();
    $adminId = (string) Str::ulid();
    $otherAdminId = (string) Str::ulid();

    $in = CheckIn::factory()->create([
        'actor_user_id' => $adminId,
        'direction' => 'in',
        'scanned_at' => now()->subHour(),
    ]);
    $out = CheckIn::factory()->create([
        'actor_user_id' => $adminId,
        'direction' => 'out',
        'scanned_at' => now(),
    ]);
    $someoneElses = CheckIn::factory()->create(['actor_user_id' => $otherAdminId]);
    $doorScan = CheckIn::factory()->create(['actor_user_id' => null]);

    $handler = new AdmissionGdprHandler;
    $export = $handler->exportFor(SubjectRef::forUser($adminId));

    // Derivation: two rows carry this admin as the actor, ordered by
    // scanned_at — the check-in an hour ago, then the check-out now. The
    // other admin's row and the door scan (no actor) are not theirs.
    expect($export['console_check_ins'])->toBe([
        [
            'id' => $in->id,
            'admission_id' => $in->admission_id,
            'direction' => 'in',
            'result' => 'accepted',
            'scanned_at' => $in->fresh()->scanned_at->toIso8601String(),
        ],
        [
            'id' => $out->id,
            'admission_id' => $out->admission_id,
            'direction' => 'out',
            'result' => 'accepted',
            'scanned_at' => $out->fresh()->scanned_at->toIso8601String(),
        ],
    ]);

    $handler->delete(SubjectRef::forUser($adminId), $mode);

    // Derivation: four rows before, four after — nothing is deleted. The two
    // rows of this admin lose the actor; the other admin's keeps theirs.
    expect(CheckIn::query()->count())->toBe(4)
        ->and($in->fresh()->actor_user_id)->toBeNull()
        ->and($out->fresh()->actor_user_id)->toBeNull()
        ->and($someoneElses->fresh()->actor_user_id)->toBe($otherAdminId)
        ->and($doorScan->fresh()->actor_user_id)->toBeNull()
        ->and($handler->exportFor(SubjectRef::forUser($adminId))['console_check_ins'])->toBe([]);
})->with([
    'anonymize' => [DeletionMode::Anonymize],
    'erase' => [DeletionMode::Erase],
]);

// #329 final review M3: `check_ins.conditions_checked` holds what the door or
// the console decided about the holder's entry conditions, so the guest's own
// export lists every check-in on their tickets, with the outcomes, and no
// admin as the actor. Erasure keeps the rows (the attendance record).
it('exports the check-ins on a holder\'s own tickets with the door-check outcomes', function (): void {
    $this->withBrand();
    $holderId = (string) Str::ulid();
    $adminId = (string) Str::ulid();
    $dayId = (string) Str::ulid();

    $ticket = Admission::factory()->create(['holder_user_id' => $holderId]);
    $someoneElses = Admission::factory()->create(['holder_user_id' => (string) Str::ulid()]);

    $refused = CheckIn::factory()->onDay($dayId)->rejected('reentry_not_permitted')->create([
        'admission_id' => $ticket->id,
        'scanned_at' => now()->subHour(),
    ]);
    $accepted = CheckIn::factory()->onDay($dayId)->create([
        'admission_id' => $ticket->id,
        'actor_user_id' => $adminId,
        'conditions_checked' => [['key' => 'student', 'outcome' => 'refused']],
        'scanned_at' => now()->subHours(2),
    ]);
    CheckIn::factory()->create(['admission_id' => $someoneElses->id]);

    $handler = new AdmissionGdprHandler;
    $export = $handler->exportFor(SubjectRef::forUser($holderId));

    // Derivation: two rows on the holder's ticket, by scanned_at — the
    // console accept two hours ago with `student: refused`, then the door's
    // refusal an hour ago, which carries no outcomes. The other holder's row
    // is not theirs, and no row names the admin.
    expect($export['check_ins'])->toBe([
        [
            'id' => $accepted->id,
            'admission_id' => $ticket->id,
            'event_day_id' => $dayId,
            'direction' => 'in',
            'result' => 'accepted',
            'reason_code' => null,
            'scanned_at' => $accepted->fresh()->scanned_at->toIso8601String(),
            'conditions_checked' => [['key' => 'student', 'outcome' => 'refused']],
        ],
        [
            'id' => $refused->id,
            'admission_id' => $ticket->id,
            'event_day_id' => $dayId,
            'direction' => 'in',
            'result' => 'rejected',
            'reason_code' => 'reentry_not_permitted',
            'scanned_at' => $refused->fresh()->scanned_at->toIso8601String(),
            'conditions_checked' => null,
        ],
    ]);

    $handler->delete(SubjectRef::forUser($holderId), DeletionMode::Erase);

    // Derivation: three rows before, three after — the holder's erasure keeps
    // the attendance rows, which then point at the tombstone only.
    expect(CheckIn::query()->count())->toBe(3);
});
