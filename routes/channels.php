<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Konventix\Community\Broadcasting\RoomChannel;
use Konventix\Identity\Models\User;

/*
 * Channel registration is host-level, because this is the file
 * `bootstrap/app.php` hands to `withBroadcasting()` and the only place the
 * application loads channel patterns from. What a module owns is the
 * *decision*: a module-owned channel registers its pattern here and points
 * at a class in the module, so that no module's access rule is written into
 * this file. Community's chat is the first of those (Competition's board
 * will be the next).
 *
 * The channel name below matches Notifiable::broadcastChannel() — the FQCN
 * with dots — which is where database notifications broadcast by default,
 * and is what the messaging bell's `NotificationReceived` broadcasts on.
 */
Broadcast::channel('Konventix.Identity.Models.User.{id}', function (User $user, string $id): bool {
    return $user->getKey() === $id;
});

/*
 * The event shoutbox's private per-room channel (design §8, ADR-0067).
 * `RoomChannel::join()` is the gate and its docblock is the reasoning:
 * admission to the room's event through Platform's `ActiveEventContext`
 * (Admission's own answer, ADR-0004), or `community.moderate-community` for
 * the desk — never Community's own `community_event_presence` badge, which
 * is displayed and never enforced (ADR-0067 decision 2).
 */
Broadcast::channel(RoomChannel::PATTERN, RoomChannel::class);
