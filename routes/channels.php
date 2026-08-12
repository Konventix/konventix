<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Konventix\Identity\Models\User;

/*
 * Host-level channels only. Module-owned channels (Messaging bell, Competition
 * board, Community chat) are registered by their module's ServiceProvider.
 *
 * The channel name matches Notifiable::broadcastChannel() — the FQCN with
 * dots — which is where database notifications broadcast by default.
 */
Broadcast::channel('Konventix.Identity.Models.User.{id}', function (User $user, string $id): bool {
    return $user->getKey() === $id;
});
