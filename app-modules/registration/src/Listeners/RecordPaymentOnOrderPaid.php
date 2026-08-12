<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\OrderPaid;
use Konventix\Registration\Actions\RecordOrderPaidForRegistration;

/**
 * The trigger for `confirmation_gate: on_payment` (SPEC.md "Consumes" —
 * `OrderPaid`, ADR-0006): Registration learns payment outcomes from
 * Commerce's events, never from Commerce models. Registered in
 * `RegistrationServiceProvider::registerListeners()`.
 *
 * `Konventix\Commerce\Events\OrderPaid` is the one Commerce class this
 * module imports, allowed because events are the public contract between
 * modules (ADR-0004). Orders can settle things other than registrations
 * (`settles_type` is an open domain string), so anything that is not a
 * registration settlement is ignored here.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as Admission's listener.
 */
final class RecordPaymentOnOrderPaid
{
    private const string SETTLES_REGISTRATION = 'registration';

    public function __construct(
        private readonly RecordOrderPaidForRegistration $action,
    ) {}

    public function handle(OrderPaid $event): void
    {
        if ($event->settlesType !== self::SETTLES_REGISTRATION) {
            return;
        }

        $this->action->handle(
            registrationId: $event->settlesId,
            brandId: $event->brandId,
        );
    }
}
