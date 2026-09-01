<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Registration\Gdpr\RegistrationGdprHandler;
use Konventix\Registration\Models\Registrant;

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
