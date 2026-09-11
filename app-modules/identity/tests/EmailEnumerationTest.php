<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Laravel\Fortify\Features;

/**
 * GitLab #18: ADR-0016 says an address that is already taken must not be
 * distinguishable from success on any form that accepts an email address.
 * Three paths named in the issue, three groups of tests in this file —
 * registration (`register.store`), the Steam signup completion
 * (`auth/{provider}/complete`), and the forgot-password request
 * (`password.email`, in a later commit). Each duplicate branch is compared
 * directly against a genuine one made in the same test, rather than against
 * a hard-coded expectation, so a future change to what "success" looks like
 * cannot silently widen the gap between the two again.
 *
 * `enumerationMailPushed()` is scoped to this file (`.ai/rules/tests.md`:
 * Pest helper names are process-global across every module's test files in
 * one run) and mirrors `AuthMailTest.php`'s `authMailPushed()` under a name
 * that cannot collide with it.
 */
function enumerationMailPushed(string $templateKey): array
{
    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    );

    return Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    )->all();
}

it('registration naming an existing address answers exactly like a genuine registration, and notifies the existing account instead of the requester', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withBrand();
    Queue::fake();

    $existing = User::factory()->create(['email' => 'taken@example.test']);

    // Duplicate first, while still a guest — a genuine registration logs the
    // test client in, and `register.store` carries `guest:web` (Fortify's
    // own route middleware), so a second POST from an authenticated client
    // would never reach the controller at all.
    $duplicate = $this->post(route('register.store'), [
        'name' => 'Impostor',
        'username' => 'impostor',
        'email' => 'taken@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $duplicate->assertSessionHasNoErrors();
    $duplicate->assertRedirect(route('dashboard', absolute: false));

    $genuine = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'brand-new@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $genuine->assertSessionHasNoErrors();
    $genuine->assertRedirect(route('dashboard', absolute: false));
    expect($genuine->status())->toBe($duplicate->status());
    $this->assertAuthenticated();

    expect(User::where('email', 'taken@example.test')->count())->toBe(1)
        ->and(User::where('username', 'impostor')->exists())->toBeFalse()
        ->and(User::where('email', 'brand-new@example.test')->exists())->toBeTrue();

    $pushed = enumerationMailPushed('identity.registration-attempt');
    expect($pushed)->toHaveCount(1);
    expect($pushed[0]->message->recipientId)->toBe($existing->getKey());
    expect($pushed[0]->message->recipientEmail)->toBe('taken@example.test');

    $payload = $pushed[0]->message->payload->toArray();
    expect($payload['resetUrl'])->toContain('/forgot-password');
});

it('Steam signup naming an existing address answers exactly like a genuine signup, and notifies the existing account instead of the requester', function (): void {
    $this->withBrand();
    Queue::fake();

    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $duplicate = $this->withSession(['identity.social.pending' => [
        'provider' => 'steam',
        'providerUserId' => '76561198000000111',
        'nickname' => 'gaben',
        'avatarUrl' => null,
        'email' => null,
    ]])->post('http://localhost/auth/steam/complete', [
        'name' => 'Impostor',
        'username' => 'impostor',
        'email' => 'taken@example.test',
    ]);

    $this->assertGuest();
    $duplicate->assertSessionHasNoErrors();
    $duplicate->assertRedirect(route('dashboard', absolute: false));

    $genuine = $this->withSession(['identity.social.pending' => [
        'provider' => 'steam',
        'providerUserId' => '76561198000000222',
        'nickname' => 'ada',
        'avatarUrl' => null,
        'email' => null,
    ]])->post('http://localhost/auth/steam/complete', [
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'brand-new@example.test',
    ]);

    $genuine->assertSessionHasNoErrors();
    $genuine->assertRedirect(route('dashboard', absolute: false));
    expect($genuine->status())->toBe($duplicate->status());
    $this->assertAuthenticated();

    expect(User::where('email', 'taken@example.test')->count())->toBe(1)
        ->and(User::where('username', 'impostor')->exists())->toBeFalse()
        ->and(User::where('email', 'brand-new@example.test')->exists())->toBeTrue();

    $pushed = enumerationMailPushed('identity.registration-attempt');
    expect($pushed)->toHaveCount(1);
    expect($pushed[0]->message->recipientId)->toBe($existing->getKey());
});
