<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Competition\Hub\NextGamesCard;
use Konventix\Platform\Hub\ActiveEventContext;

/**
 * The hub's *Nächste Spiele* card as JSON (design §10a "Liveness"): exactly
 * the payload the hub rendered the card with, rebuilt for the card alone,
 * so its minute-by-minute refresh never re-runs the other modules' cards
 * nor counts as a hub view.
 *
 * The gate is the hub's own: the running event this member is admitted to
 * on this brand (`ActiveEventContext`, hub design §5.1). Nobody admitted is
 * a 404 — a JSON caller has no use for the hub page's redirect home.
 * `auth`, the module gate and the `competition-hub` budget sit on the route.
 */
final class NextGamesController
{
    public function __invoke(Request $request, NextGamesCard $card): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $event = app(ActiveEventContext::class)->current();
        abort_if($event === null, 404);

        return new JsonResponse($card($event, $user)->toArray());
    }
}
