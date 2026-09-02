<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * Steam proves who somebody is and tells us nothing else — no email, and
 * (without a Web API key) not even a name. So a signup that starts at Steam
 * still has to ask, and the account it produces has no password at all.
 */
function identityPendingSteamSignup(array $overrides = []): array
{
    return array_merge([
        'provider' => 'steam',
        'providerUserId' => '76561198000000900',
        'nickname' => 'gaben',
        'avatarUrl' => null,
        'email' => null,
    ], $overrides);
}

it('creates a passwordless account and links the identity', function (): void {
    $this->withBrand();

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Ada Lovelace',
            'username' => 'ada-lovelace',
            'email' => 'ada@example.test',
        ])->assertRedirect();

    $user = User::query()->firstWhere('email', 'ada@example.test');

    expect($user)->not->toBeNull()
        ->and($user->hasPassword())->toBeFalse()
        ->and(auth()->id())->toBe($user->getKey())
        ->and($user->socialIdentities()->where('provider_user_id', '76561198000000900')->exists())->toBeTrue();
});

/**
 * Three modules build their read model of people from `UserRegistered` alone,
 * and Fortify's `Registered` is what sends the verification mail and records
 * the brand membership. An account created down this path that skipped either
 * would be one the rest of the system had never heard of.
 */
it('announces the new account exactly as ordinary registration does', function (): void {
    $this->withBrand();
    Event::fake([UserRegistered::class, Registered::class]);

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Grace Hopper',
            'username' => 'grace-hopper',
            'email' => 'grace@example.test',
        ]);

    Event::assertDispatched(UserRegistered::class);
    Event::assertDispatched(Registered::class);
});

it('sends the verification mail to the address the person gave', function (): void {
    $this->withBrand();

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Ada Lovelace',
            'username' => 'ada-lovelace',
            'email' => 'ada@example.test',
        ]);

    $messages = Mail::mailer()->getSymfonyTransport()->messages();

    expect($messages)->not->toBeEmpty()
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('ada@example.test');
});

/**
 * The identity is read from the session, never from the request. If it could be
 * posted, anyone could name any SteamID64 and have an account minted against
 * it without Steam ever being involved.
 */
it('ignores a provider identity the request tries to name itself', function (): void {
    $this->withBrand();

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Ada Lovelace',
            'username' => 'ada-lovelace',
            'email' => 'ada@example.test',
            'providerUserId' => '76561198000000999',
            'provider_user_id' => '76561198000000999',
        ]);

    expect(SocialIdentity::query()->where('provider_user_id', '76561198000000999')->exists())->toBeFalse()
        ->and(SocialIdentity::query()->where('provider_user_id', '76561198000000900')->exists())->toBeTrue();
});

it('sends somebody back to the start when the handshake has expired', function (): void {
    $this->withBrand();

    $this->get('http://localhost/auth/steam/complete')
        ->assertRedirect(route('login'));

    $this->post('http://localhost/auth/steam/complete', [
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'ada@example.test',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

/**
 * Steam asserts no email, so a matching address proves nothing about who typed
 * it — auto-linking here would be account takeover by typing. The signup is
 * refused and no account is touched.
 */
it('refuses a signup naming an address that already exists, and links nothing', function (): void {
    $this->withBrand();

    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->post('http://localhost/auth/steam/complete', [
            'name' => 'Impostor',
            'username' => 'impostor',
            'email' => 'taken@example.test',
        ])->assertSessionHasErrors('email');

    expect($existing->socialIdentities()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse()
        ->and(SocialIdentity::query()->count())->toBe(0);
});

it('shows the completion page with the provider name it came from', function (): void {
    $this->withBrand();

    $this->withSession(['identity.social.pending' => identityPendingSteamSignup()])
        ->get('http://localhost/auth/steam/complete')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/CompleteSocialSignup')
            ->where('providerLabel', 'Steam')
            ->where('suggestedName', 'gaben'));
});
