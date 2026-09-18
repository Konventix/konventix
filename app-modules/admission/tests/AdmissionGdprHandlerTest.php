<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Gdpr\AdmissionGdprHandler;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
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
