<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Laravel\Fortify\Features;

/**
 * ADR-0048 point 5: the reset-link mail this flow triggers goes through
 * Messaging (`Konventix\Identity\Mail\AuthMailer::sendPasswordReset()`)
 * rather than Laravel's stock `ResetPassword` notification, so the raw
 * token these tests need is read off `identity.password-reset`'s pushed
 * payload (`resetUrl`) instead of a faked notification instance — see
 * `app-modules/identity/tests/AuthMailTest.php` for the mail itself.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.password-reset'
            && $job->message->recipientId === $user->getKey(),
    );
});

test('reset password screen can be rendered', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.password-reset',
    )->firstOrFail();

    $token = basename((string) parse_url($job->message->payload->toArray()['resetUrl'], PHP_URL_PATH));

    $response = $this->get(route('password.reset', $token));

    $response->assertOk();
});

test('password can be reset with valid token', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'identity.password-reset',
    )->firstOrFail();

    $token = basename((string) parse_url($job->message->payload->toArray()['resetUrl'], PHP_URL_PATH));

    $response = $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
