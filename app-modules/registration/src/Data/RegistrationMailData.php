<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload behind every registration lifecycle mail (ADR-0009,
 * ADR-0048 point 3) — one shape for `registration.submitted`, `.approved`,
 * `.rejected`, `.confirmed` and `.cancelled`, since they say the same kind
 * of thing (what changed, what it means, where to look) with different
 * fields lit up.
 *
 * No field here is a precomputed sentence: `SendMessage` resolves the
 * recipient's locale from Messaging's own read model, so a German greeting
 * or wording baked into the payload by this module would be wrong the
 * moment the recipient's locale is `en`. `nextStep` (`approval`/`pay`/
 * `invoice`/`confirmed`), `reason` (the raw rejection `reasonCode`, mapped
 * with a fallback to itself) and `cancelReason`
 * (`cart_expired`/`registrant`/`operator`) are codes the templates branch
 * on with whitespace-safe `@if`/`@elseif` (`.ai/rules/app-modules-src.md`)
 * — the one place a registration mail template is not branch-free.
 * `holderName` stands in for a precomputed greeting too: every body opens
 * with `Guten Tag{{ $holderName ? ' '.$holderName : '' }},` inline.
 *
 * `cartUrl`/`cartHoldExpiresAt` are the one pair that carries a formatted
 * timestamp rather than a code (`WaitlistOfferMailData::$offerExpiresAt`
 * set that precedent): a deadline is a number and a separator, not a
 * sentence, and the recipient needs to read it in the brand's own zone
 * (#210). Both null unless the settlement went into the registrant's cart
 * — an order-settled or free registration says nothing about a cart.
 */
#[TypeScript]
final class RegistrationMailData extends Data
{
    public function __construct(
        public readonly string $registrationTypeName,
        public readonly string $registrationUrl,
        public readonly ?string $eventName = null,
        public readonly ?string $holderName = null,
        public readonly ?string $nextStep = null,
        public readonly ?string $reason = null,
        public readonly ?string $cancelReason = null,
        public readonly bool $wasPaid = false,
        public readonly ?string $cartUrl = null,
        public readonly ?string $cartHoldExpiresAt = null,

        /**
         * D-REG-15's pair, lit up by `registration.quantity-reduced` alone:
         * how many units were taken off the act and how many are left.
         * Numbers rather than a sentence, for the same reason every code
         * above is a code — the recipient's locale is resolved when the
         * mail is actually sent, not here.
         */
        public readonly ?int $removedUnits = null,
        public readonly ?int $newQuantity = null,
    ) {}
}
