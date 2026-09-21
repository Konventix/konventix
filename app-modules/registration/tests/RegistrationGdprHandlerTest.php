<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\RegistrationType;

/**
 * The email branch ADR-0050 §3 owes Registration (slice 20 fix-wave I1b):
 * `registrant_user_id` on `registrations`/`waitlist_entries` is never
 * nullable (D-REG-08), so those tables have nothing to say about a bare
 * address. But `Registrant` — the organizer-list read model projected from
 * Identity's account events — is populated from an account and never
 * cleaned up when that account is later anonymized elsewhere, leaving a
 * stale address reachable only by email once the account no longer
 * resolves to it.
 */
it('exports registrant rows matching an email-only subject', function (): void {
    $this->withBrand();

    $registrant = Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Stale Registrant',
        'email' => 'stale@example.de',
    ]);

    Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Someone Else',
        'email' => 'someone-else@example.de',
    ]);

    $export = (new RegistrationGdprHandler)->exportFor(SubjectRef::forEmail('stale@example.de'));

    expect($export['registrants'])->toHaveCount(1)
        ->and($export['registrants'][0]['id'])->toBe($registrant->id)
        ->and($export['registrants'][0]['display_name'])->toBe('Stale Registrant');
});

it('anonymizes the registrant row matching an email-only subject', function (): void {
    $this->withBrand();

    $registrant = Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Stale Registrant',
        'email' => 'stale@example.de',
    ]);
    $other = Registrant::query()->create([
        'id' => (string) Str::ulid(),
        'display_name' => 'Someone Else',
        'email' => 'someone-else@example.de',
    ]);

    (new RegistrationGdprHandler)->delete(SubjectRef::forEmail('stale@example.de'), DeletionMode::Anonymize);

    expect($registrant->refresh()->email)->not->toBe('stale@example.de')
        ->and($registrant->display_name)->toBe('Deleted account')
        ->and($other->refresh()->email)->toBe('someone-else@example.de');
});

it('no-ops for an email with no registrant row', function (): void {
    $this->withBrand();

    $export = (new RegistrationGdprHandler)->exportFor(SubjectRef::forEmail('nobody@example.de'));

    expect($export['registrants'])->toBe([]);
});

/**
 * ADR-0066 §11: an entry-condition declaration rides with the registration
 * in both directions.
 *
 * Exported as **the wording the person actually agreed to** — the snapshot,
 * never the condition's current text, because that is what they are entitled
 * to see — and anonymized rather than deleted, so the tombstone keeps the
 * registration's history coherent while both user references are forgotten.
 */
it('exports an entry-condition declaration as the wording that was shown', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );

    $shown = $condition->assertion_text;
    $condition->forceFill(['assertion_text' => 'Reworded since.'])->save();

    $export = (new RegistrationGdprHandler)->exportFor(SubjectRef::forUser((string) $user->getKey()));

    expect($export['entry_condition_assertions'])->toHaveCount(1)
        ->and($export['entry_condition_assertions'][0]['condition_name'])->toBe('Student ticket')
        ->and($export['entry_condition_assertions'][0]['asserted_text'])->toBe($shown)
        ->and($export['entry_condition_assertions'][0]['state'])->toBe('asserted')
        ->and($export['entry_condition_assertions'][0]['decided_at'])->toBeNull();
});

it('anonymizes a declaration idempotently and keeps the row', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $condition = EntryCondition::factory()->student()->create();
    $type = RegistrationType::factory()->create();
    $type->entryConditions()->attach($condition->id, ['position' => 0]);

    app(SubmitRegistration::class)->handle(
        $type,
        (string) $user->getKey(),
        entryConditionSubmission([$condition->id]),
    );

    $handler = new RegistrationGdprHandler;
    $subject = SubjectRef::forUser((string) $user->getKey());

    $handler->delete($subject, DeletionMode::Anonymize);
    $handler->delete($subject, DeletionMode::Anonymize);

    $assertion = EntryConditionAssertion::withoutBrandScope()->firstOrFail();

    expect(EntryConditionAssertion::withoutBrandScope()->count())->toBe(1)
        ->and($assertion->subject_user_id)->toBeNull()
        ->and($assertion->decided_by_user_id)->toBeNull()
        ->and($assertion->assertion_text_snapshot)->not->toBeEmpty();
});
