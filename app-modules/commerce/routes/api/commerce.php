<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Controllers\Api\StripeWebhookController;

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

Route::prefix('commerce/webhooks')
    ->name('api.commerce.webhooks.')
    ->group(function (): void {
        Route::post('stripe', StripeWebhookController::class)->name('stripe');
    });
