<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Models\User;

/**
 * The `/settings/profile` form widened to the profile-of-record fields
 * (ADR-0051): username, tagline, status emoji, social links. The username
 * rules are shared with registration (`ProfileValidationRules::usernameRules()`,
 * #143); tagline, emoji and social links live only in `ProfileUpdateData`.
 */
function validProfilePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'ada-lovelace',
        'tagline' => 'First programmer',
        'statusEmoji' => '🚀',
        'socialLinks' => ['x' => 'ada', 'website' => 'https://example.test'],
        'visibility' => 'internal',
    ], $overrides);
}

it('updates the profile-of-record', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => $user->name, 'email' => $user->email,
        'username' => 'ada-lovelace', 'tagline' => 'First programmer',
        'statusEmoji' => '🚀',
        'socialLinks' => ['x' => 'ada', 'website' => 'https://example.test'],
        'visibility' => 'internal',
    ])->assertRedirect();

    $user->refresh();
    expect($user->username)->toBe('ada-lovelace')
        ->and($user->social_links)->toBe(['x' => 'ada', 'website' => 'https://example.test']);
});

it('refuses a reserved username', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['username' => 'admin']))
        ->assertInvalid(['username']);
});

it('refuses a taken username case-insensitively', function (): void {
    $this->withBrand();
    User::factory()->create(['username' => 'ada']);
    $user = User::factory()->create();

    // Uppercase fails the lowercase-only format rule, not the taken check.
    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['username' => 'ADA']))
        ->assertInvalid(['username']);

    // Lowercase matches the format but collides case-insensitively.
    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['username' => 'ada']))
        ->assertInvalid(['username']);
});

it('refuses a 141-character tagline', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['tagline' => str_repeat('a', 141)]))
        ->assertInvalid(['tagline']);
});

it('refuses more than one emoji grapheme and refuses plain ascii', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['statusEmoji' => '🚀🚀']))
        ->assertInvalid(['statusEmoji']);

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['statusEmoji' => 'x']))
        ->assertInvalid(['statusEmoji']);
});

it('refuses an unknown social platform key', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['socialLinks' => ['myspace' => 'x']]))
        ->assertInvalid(['socialLinks']);
});

it('refuses a malformed platform handle', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['socialLinks' => ['x' => 'has spaces']]))
        ->assertInvalid(['socialLinks.x']);
});

it('keeps username unchanged when resubmitted by its owner', function (): void {
    $this->withBrand();
    $user = User::factory()->create(['username' => 'ada-lovelace']);

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['username' => 'ada-lovelace']))
        ->assertValid(['username'])
        ->assertRedirect();
});

it('normalizes empty tagline and status emoji to null and empty social links to null', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => $user->name, 'email' => $user->email,
        'username' => 'ada-lovelace', 'tagline' => '', 'statusEmoji' => '',
        'socialLinks' => ['steam' => '', 'x' => ''],
        'visibility' => 'internal',
    ])->assertRedirect();

    $user->refresh();
    expect($user->tagline)->toBeNull()
        ->and($user->status_emoji)->toBeNull()
        ->and($user->social_links)->toBeNull();
});

it('passes the current profile-of-record as a page prop', function (): void {
    $this->withBrand();
    $user = User::factory()->create([
        'username' => 'ada-lovelace',
        'tagline' => 'First programmer',
        'status_emoji' => '🚀',
        'social_links' => ['x' => 'ada'],
        'profile_visibility' => ProfileVisibility::Public,
    ]);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Profile')
            ->where('profile.name', $user->name)
            ->where('profile.email', $user->email)
            ->where('profile.username', 'ada-lovelace')
            ->where('profile.tagline', 'First programmer')
            ->where('profile.statusEmoji', '🚀')
            ->where('profile.socialLinks', ['x' => 'ada'])
            ->where('profile.visibility', 'public'));
});

it('explains the expected shape of a malformed social handle', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', validProfilePayload($user, ['socialLinks' => ['x' => '@ada', 'website' => 'example.test']]))
        ->assertInvalid([
            'socialLinks.x' => 'X: the handle only, 1-15 letters, digits or _, without the @.',
            'socialLinks.website' => 'Website: a full address starting with http:// or https://.',
        ]);
});
