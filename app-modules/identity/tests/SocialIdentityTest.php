<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * The table behind social login, and the one change it forces on password
 * authentication.
 *
 * Both unique constraints exist to stop a class of account confusion rather
 * than to tidy the data: one provider identity must not back two logins, and
 * one account must not accumulate several identities at the same provider.
 */
it('refuses to let one provider identity back two accounts', function (): void {
    SocialIdentity::factory()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);

    SocialIdentity::factory()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);
})->throws(QueryException::class);

it('refuses a second identity at the same provider for one account', function (): void {
    $user = User::factory()->create();

    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);
})->throws(QueryException::class);

/**
 * A Steam-first account genuinely has no password (decision: the column is
 * nullable rather than filled with a placeholder). The password login path
 * must refuse it outright — a null hash must never be coerced into something
 * `Hash::check` will compare.
 */
it('never authenticates a passwordless account through the password path', function (): void {
    $user = User::factory()->create(['password' => null]);

    expect($user->hasPassword())->toBeFalse();

    $this->post('/login', [
        'email' => $user->email,
        'password' => '',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});

it('still authenticates an account that does have a password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

    expect($user->hasPassword())->toBeTrue();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse',
    ]);

    expect(auth()->id())->toBe($user->getKey());
});

/**
 * Slice 15a's promise in Identity's TODO: "Social identities join the export
 * when social login lands."
 */
it('exports linked identities and erases them on anonymization', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000123',
        'nickname' => 'gaben',
    ]);

    $handler = new IdentityGdprHandler;

    $export = $handler->exportFor((string) $user->getKey(), (string) $brand->getKey());

    expect($export['social_identities'])->toHaveCount(1)
        ->and($export['social_identities'][0]['provider_user_id'])->toBe('76561198000000123');

    $handler->delete((string) $user->getKey(), (string) $brand->getKey(), DeletionMode::Anonymize);

    // Gone entirely, not tombstoned: the row is nothing but an identifier for
    // the person who asked to be forgotten, and leaving it would keep the
    // Steam login working against an anonymized account.
    expect(SocialIdentity::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
});
