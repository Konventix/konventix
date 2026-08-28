<?php

declare(strict_types=1);

namespace Konventix\Identity\Listeners;

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Models\User;

/**
 * ADR-0048 point 5: the welcome that follows the first verification.
 * `Verified` carries only the account — no brand — so the brand this welcome
 * addresses is resolved the same way `AuthMailer`'s other sends resolve one:
 * the ambient `BrandContext` (the signed link was followed on a brand host,
 * the ordinary path) falling back to the account's first brand membership.
 * A user with neither is skipped with a log rather than guessed at.
 */
final class SendWelcomeOnVerified
{
    public function __construct(private readonly AuthMailer $mailer) {}

    public function handle(Verified $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $brandId = $this->mailer->resolveBrandId($user);

        if ($brandId === null) {
            Log::warning('Welcome mail skipped: no brand could be resolved for the account.', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $this->mailer->sendWelcome($user, $brandId);
    }
}
