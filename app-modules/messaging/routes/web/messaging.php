<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Messaging\Http\Controllers\NotificationPreferencesController;
use Konventix\Messaging\Http\Controllers\NotificationsController;
use Konventix\Messaging\Http\Controllers\PushSubscriptionsController;

Route::middleware(['auth'])->group(function (): void {
    // The preferences page lives with the account's other settings pages;
    // Identity links here — the rows live in Messaging (ADR-0041,
    // D-MSG-06).
    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])
        ->name('notification-preferences.edit');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])
        ->name('notification-preferences.update');

    // The inbox as a page: everything the bell's short list leaves out,
    // paginated. Holds the `/notifications` path because that is what a
    // person following a link expects to land on; the bell's feed moved a
    // level deeper.
    Route::get('notifications', [NotificationsController::class, 'page'])
        ->name('notifications.index');

    // The bell's JSON endpoint (ADR-0041 point 6): fetched once per browser
    // page load by the persistent layout, live-updated over Reverb.
    Route::get('notifications/feed', [NotificationsController::class, 'index'])
        ->name('notifications.feed');
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'read'])
        ->name('notifications.read');

    // Web-push device subscriptions (ADR-0041 point 7): registered from
    // the notification settings page after the permission grant.
    Route::post('push-subscriptions', [PushSubscriptionsController::class, 'store'])
        ->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionsController::class, 'destroy'])
        ->name('push-subscriptions.destroy');
});

/*
 * Routes owned by the Messaging module.
 *
 * This file lives in `routes/web/`, one level deeper than Laravel's default, on
 * purpose: InterNACHI/modular auto-requires every `routes/*.php` with no
 * middleware at all. From here, the module's ServiceProvider is the only thing
 * that loads it, and it always wraps these routes in the `web` group plus —
 * for gateable modules — `ensure-module:messaging` (ADR-0003).
 *
 * So: declare bare routes here. Do not re-apply `web` or the module gate, and
 * never register these routes from routes/web.php.
 */
