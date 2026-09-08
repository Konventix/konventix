<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;

/**
 * This instance has no Stripe secret key — in neither of the two places one
 * may live (ADR-0058 point 2: the operator's console-entered settings row
 * wins, otherwise the environment).
 *
 * Nothing catches it. It was introduced so the settlement console could catch
 * exactly this one and repeat its message instead of answering 500 (GitLab
 * #194), and that catch site went with the Connect console in ADR-0058: the
 * payer path now short-circuits on `SettlementAccount::isOffered()` before an
 * adapter is ever resolved, and the console reads `StripeSetupData` rather
 * than provoking a client. `isOffered()` asks the *stricter* question — the
 * secret key and the webhook signing secret both, because a checkout that
 * cannot be verified must not start (ADR-0039 point 2) — while this is thrown
 * on the missing key alone, so that a refund or a reconciliation sweep still
 * runs on an instance whose signing secret has yet to be entered. Anything
 * that gets past `isOffered()` and still meets this has no key at all.
 *
 * It stays a class of its own for the message, which is the whole point: it
 * names what is missing *and* both places it can be put, so an operator who
 * meets it in a log, a queue failure or the reconciliation command does not
 * need to find this file. The refund path (`RefundOrder`) resolves the adapter
 * unconditionally, and its HTTP caller is `OrderAdminController::refund()`
 * (#73), which catches `RuntimeException` and repeats this message to the
 * operator as a form error — so the debt this comment used to record is
 * discharged: refunding a Stripe order on an instance with no key is a
 * sentence in the dialog, not a 500 nobody reads.
 */
final class StripeNotConfiguredException extends RuntimeException
{
    public static function missingPlatformKey(): self
    {
        return new self(
            'Stripe is not configured on this deployment: no secret key for this instance\'s own Stripe '
            .'account is set, so nothing can be charged, refunded or reconciled (ADR-0058). '
            .'There are two places to put it, and the first one wins: enter the Secret Key and the '
            .'Webhook Signing Secret in the operator console under Settlement accounts → Stripe account, '
            .'where they are stored encrypted; or set STRIPE_SECRET — and STRIPE_WEBHOOK_SECRET beside it, '
            .'which signs the webhooks that are the only source of settlement truth — in the environment '
            .'this deployment reads; the app sees them as `services.stripe.secret` and '
            .'`services.stripe.webhook_secret`. On a chart deployment both keys are the `secrets.payments` '
            .'group of konventix-helm\'s values.yaml. '
            .'A cached config does not follow a changed environment: run `artisan config:clear` after setting them.',
        );
    }
}
