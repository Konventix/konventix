<?php

declare(strict_types=1);

namespace Konventix\Identity\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Konventix\Identity\Data\PasswordResetMailData;
use Konventix\Identity\Data\VerifyEmailMailData;
use Konventix\Identity\Data\WelcomeMailData;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * ADR-0048 point 5: Identity's authentication mail — the two links Laravel's
 * own contracts (`MustVerifyEmail`, `CanResetPassword`) ask an app to send,
 * plus the welcome that follows the first verification — goes through
 * Messaging instead of Laravel's stock notifications, so every one of them
 * carries the brand's sender identity and branded layout and leaves a
 * delivery-log row. Laravel keeps generating and validating the signed
 * link/token; this class only decides where it is sent and how it reads
 * (`User::sendEmailVerificationNotification()` / `sendPasswordResetNotification()`,
 * `Listeners\SendWelcomeOnVerified`).
 *
 * `BrandContext` is a scoped binding (`.ai/rules/providers.md`): resolved
 * per call via `resolveBrandId()`, never constructor-injected, so this class
 * stays safe to build once per Octane worker. A console/queue caller carries
 * no ambient brand at all — the account's own first brand membership stands
 * in; a user with neither (should not happen once registration always runs
 * behind `ResolveBrand`, but costs nothing to guard) is skipped with a
 * `Log::warning`, the same shape `PromoteFromWaitlist::notify()` established
 * for a `SendMessage` failure.
 */
final class AuthMailer
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly BrandUrl $brandUrl,
    ) {}

    /**
     * The URL is built exactly as Laravel's own `VerifyEmail::
     * verificationUrl()` does, absolute (the default) rather than prefixed
     * with `BrandUrl::baseFor()`: a signed route's signature is computed
     * over the exact string it will later be re-validated against
     * (`URL::hasCorrectSignature()` checks `$request->url()`, the absolute
     * form), so signing a relative path and prepending a brand base would
     * produce a URL whose signature never matches. `absolute: true` uses
     * the current request's host in a web request — the ordinary path, and
     * already the right brand host since verification happens on it — and
     * degrades to `config('app.url')` with no ambient request at all
     * (console/queue), same as Registration's `WaitlistOfferMailData` link.
     */
    public function sendVerification(User $user): void
    {
        $brandId = $this->resolveBrandId($user);

        if ($brandId === null) {
            $this->logSkipped('Verification', $user);

            return;
        }

        $validMinutes = (int) config('auth.verification.expire', 60);

        $this->send($user, $brandId, 'identity.verify-email', new VerifyEmailMailData(
            verifyUrl: URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes($validMinutes),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            ),
            validMinutes: $validMinutes,
        ));
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $brandId = $this->resolveBrandId($user);

        if ($brandId === null) {
            $this->logSkipped('Password reset', $user);

            return;
        }

        $validMinutes = (int) config('auth.passwords.users.expire');

        $this->send($user, $brandId, 'identity.password-reset', new PasswordResetMailData(
            resetUrl: $this->absoluteRouteUrl('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], Brand::query()->find($brandId)),
            validMinutes: $validMinutes,
        ));
    }

    /**
     * `$brandId` is resolved by the caller (`Listeners\SendWelcomeOnVerified`
     * — `Illuminate\Auth\Events\Verified` carries only the account, no
     * brand) via `resolveBrandId()`, the same fallback every other method
     * here uses.
     */
    public function sendWelcome(User $user, string $brandId): void
    {
        $brand = Brand::query()->find($brandId);

        if ($brand === null) {
            Log::warning('Welcome mail skipped: brand no longer exists.', [
                'user_id' => $user->id,
                'brand_id' => $brandId,
            ]);

            return;
        }

        $homeUrl = $this->brandUrl->baseFor($brand) ?? route('home', [], absolute: true);

        $this->send($user, $brandId, 'identity.welcome', new WelcomeMailData(
            brandName: $brand->name,
            homeUrl: $homeUrl,
            shopUrl: Feature::for($brand)->active('commerce:shop') ? rtrim($homeUrl, '/').'/shop' : null,
        ), topicKey: 'identity.welcome');
    }

    /**
     * `app(BrandContext::class)->id()` first — the ambient brand of the
     * request the signed link/token was issued or followed from — falling
     * back to the account's earliest `BrandMembership` when there is none
     * (console/queue context). Shared with `Listeners\SendWelcomeOnVerified`,
     * which needs the same answer before it can call `sendWelcome()`.
     */
    public function resolveBrandId(User $user): ?string
    {
        return app(BrandContext::class)->id() ?? BrandMembership::withoutBrandScope()
            ->where('user_id', $user->id)
            ->orderBy('first_seen_at')
            ->value('brand_id');
    }

    private function send(User $user, string $brandId, string $templateKey, Data $payload, ?string $topicKey = null): void
    {
        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $user->id,
                templateKey: $templateKey,
                payload: $payload,
                recipientEmail: $user->email,
                recipientLocale: $user->locale,
                brandId: $brandId,
                topicKey: $topicKey,
            ));
        } catch (RuntimeException $exception) {
            Log::warning('Auth mail could not be sent.', [
                'user_id' => $user->id,
                'template_key' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function logSkipped(string $kind, User $user): void
    {
        Log::warning(sprintf('%s mail skipped: no brand could be resolved for the account.', $kind), [
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function absoluteRouteUrl(string $routeName, array $parameters, ?Brand $brand): string
    {
        $base = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $base === null
            ? route($routeName, $parameters, absolute: true)
            : rtrim($base, '/').route($routeName, $parameters, absolute: false);
    }
}
