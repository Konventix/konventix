<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Gdpr\SubjectRef;

it('carries a user id and reports itself account-backed', function (): void {
    $ulid = (string) Str::ulid();
    $subject = SubjectRef::forUser($ulid);

    expect($subject->userId)->toBe($ulid)
        ->and($subject->email)->toBeNull()
        ->and($subject->isAccountBacked())->toBeTrue()
        ->and($subject->key())->toBe($ulid);
});

it('carries an email and reports itself not account-backed', function (): void {
    $subject = SubjectRef::forEmail('Someone@Example.DE');

    expect($subject->userId)->toBeNull()
        // Lower-cased on the way in: the address is a lookup key against
        // messaging_suppressions.email, and a case-varied duplicate would
        // silently miss the suppression that already exists.
        ->and($subject->email)->toBe('someone@example.de')
        ->and($subject->isAccountBacked())->toBeFalse()
        ->and($subject->key())->toBe('someone@example.de');
});

it('refuses an empty user id', function (): void {
    SubjectRef::forUser('');
})->throws(InvalidArgumentException::class);

it('refuses an empty email', function (): void {
    SubjectRef::forEmail('  ');
})->throws(InvalidArgumentException::class);
