<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\Api\MollieWebhookController;
use Konventix\Commerce\Http\Controllers\Api\StripeWebhookController;
use Konventix\Commerce\Http\Controllers\Api\UnzerWebhookController;
use Konventix\Commerce\Http\Middleware\RecordWebhookMetrics;

/*
 * Commerce's stateless machine surface (ADR-0039): PSP webhooks. No
 * session, no CSRF, no brand resolved from the host — each handler
 * authenticates the caller its provider's way (Stripe: payload signature;
 * Mollie/Unzer: the payload is an untrusted hint and truth is fetched back
 * from the provider's API).
 *
 * Bare routes only. The `api` group is applied by ModuleServiceProvider;
 * re-applying it here would double it up.
 */

/*
 * The named limiter is defined in `CommerceServiceProvider::configureRateLimiting()`
 * (#71): `bootstrap/app.php` applies no `api` throttler at all, and Mollie
 * and Unzer answer every single request with a call to the provider's own
 * API (ADR-0039's fetch-back), so an unlimited endpoint is a cost on the
 * organizer's quota rather than merely noise in the log.
 */
Route::prefix('commerce/webhooks')
    ->middleware(['throttle:commerce-webhooks', RecordWebhookMetrics::class])
    ->name('api.commerce.webhooks.')
    ->group(function (): void {
        Route::post('stripe', StripeWebhookController::class)->name('stripe');
        // {payment} is our Payment ULID, baked into the webhookUrl at
        // checkout creation — the body Mollie posts is ignored on purpose.
        Route::post('mollie/{payment}', MollieWebhookController::class)->name('mollie');
        // {account} is our SettlementAccount ULID — Unzer notifies per
        // account; the payment is correlated via the fetched orderId.
        Route::post('unzer/{account}', UnzerWebhookController::class)->name('unzer');
    });
