<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Fortify\Features;

/**
 * ADR-0048 point 5: Identity's authentication mail goes through Messaging —
 * `User::sendEmailVerificationNotification()` / `sendPasswordResetNotification()`
 * call `Mail\AuthMailer`, and `Listeners\SendWelcomeOnVerified` follows the
 * first verification with `identity.welcome`. `authMail*` helper names are
 * scoped to this file — .ai/rules/tests.md: Pest helper function names are
 * process-global across every module's test files in one run.
 */
function authMailPushed(string $templateKey): array
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

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

it('sends identity.verify-email with a signed link on registration, and following it verifies the account', function (): void {
    $this->withBrand();
    Queue::fake();

    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada-lovelace',
        'email' => 'ada@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'ada@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();

    $pushed = authMailPushed('identity.verify-email');
    expect($pushed)->toHaveCount(1);
    expect($pushed[0]->message->recipientId)->toBe($user->getKey());
    expect($pushed[0]->message->recipientEmail)->toBe('ada@example.test');

    $payload = $pushed[0]->message->payload->toArray();
    expect($payload['verifyUrl'])->toContain('signature=');

    $this->actingAs($user)->get($payload['verifyUrl']);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('sends identity.password-reset with a link naming the token route and the email, on forgot-password', function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
    $this->withBrand();
    Queue::fake();

    $user = User::factory()->create(['email' => 'reset-me@example.test']);

    $this->post(route('password.email'), ['email' => 'reset-me@example.test']);

    $pushed = authMailPushed('identity.password-reset');
    expect($pushed)->toHaveCount(1);
    expect($pushed[0]->message->recipientId)->toBe($user->getKey());

    $payload = $pushed[0]->message->payload->toArray();
    expect($payload['resetUrl'])->toContain('/reset-password/')
        ->and($payload['resetUrl'])->toContain('email=');
    expect(parse_url($payload['resetUrl'], PHP_URL_QUERY))->toContain('reset-me%40example.test');
});

it('sends identity.welcome on its own topic when a user is verified', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $user = User::factory()->unverified()->create();

    event(new Verified($user));

    $pushed = authMailPushed('identity.welcome');
    expect($pushed)->toHaveCount(1);
    expect($pushed[0]->message->topicKey)->toBe('identity.welcome');
    expect($pushed[0]->message->recipientId)->toBe($user->getKey());

    $payload = $pushed[0]->message->payload->toArray();
    expect($payload['brandName'])->toBe($brand->name);
    expect($payload['shopUrl'])->toBeNull();
});

it('logs a warning and sends nothing when no brand can be resolved for the welcome mail', function (): void {
    app(BrandContext::class)->forget();
    Queue::fake();
    Log::spy();

    $user = User::factory()->unverified()->create();

    event(new Verified($user));

    Queue::assertNotPushed(SendQueuedMessage::class);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no brand could be resolved')
            && $context['user_id'] === $user->getKey());
});

it('references no Laravel stock auth notification anywhere under app-modules', function (): void {
    $needlePrefix = 'Illuminate'.'\\Auth\\Notifications\\';
    $needles = [$needlePrefix.'VerifyEmail', $needlePrefix.'ResetPassword'];

    $offenders = [];

    foreach (File::allFiles(base_path('app-modules')) as $file) {
        if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
            continue;
        }

        $contents = File::get($file->getRealPath());

        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
