<?php

declare(strict_types=1);

namespace Konventix\Messaging\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates the deployment's VAPID key pair (ADR-0041 point 7). Run once,
 * put the values in `.env` — the keys identify this deployment to the push
 * services and every subscription is bound to them, so rotating them
 * silently invalidates every stored subscription.
 */
final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'messaging:vapid-keys';

    protected $description = 'Generate a VAPID key pair for web push (Messaging).';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Add to .env (rotating later invalidates all stored subscriptions):');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
