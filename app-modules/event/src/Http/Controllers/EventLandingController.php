<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Konventix\Event\Data\EventData;
use Konventix\Event\Models\Event;

/**
 * The public event landing route (SPEC.md "Event visibility" — "the public
 * landing route resolved on the brand's own domain").
 *
 * Renders JSON rather than an Inertia page for now: module-local
 * `resources/js/pages` are not wired into page resolution — `vite.config.js`
 * configures `@inertiajs/vite` with no `directory` option, so it only globs
 * the host's `resources/js/pages`, not `app-modules/*\/resources/js/pages`
 * (see `.ai/rules/resources.md`, which documents the intended convention,
 * against the actual, unwired build config). Wiring that glob is a frontend
 * build-pipeline change outside this module's `app-modules/event/` scope —
 * see TODO.md.
 */
final class EventLandingController
{
    public function show(Event $event): JsonResponse
    {
        abort_unless($event->isVisibleOnLanding(), 404);

        return response()->json(EventData::fromModel($event));
    }
}
