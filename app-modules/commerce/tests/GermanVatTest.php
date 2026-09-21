<?php

declare(strict_types=1);

use Konventix\Commerce\Tax\GermanVat;

/**
 * §33 UStDV's Kleinbetragsrechnung ceiling (#4): a gross total up to and
 * including 250.00 EUR may omit the recipient's postal address; a genuinely
 * higher total falls under §14 Abs. 4 UStG's full invoice requirements
 * instead. `PlaceOrderFromCart` and `IssueInvoiceOnOrderPaid` both read this
 * one place rather than repeating the figure.
 */
it('stays exempt at exactly the 250 gross ceiling', function (): void {
    expect(GermanVat::requiresBillingAddress('250.00'))->toBeFalse();
});

it('demands an address one cent above the ceiling', function (): void {
    expect(GermanVat::requiresBillingAddress('250.01'))->toBeTrue();
});

it('stays exempt comfortably under the ceiling', function (): void {
    expect(GermanVat::requiresBillingAddress('249.99'))->toBeFalse();
});

it('demands an address well above the ceiling', function (): void {
    expect(GermanVat::requiresBillingAddress('500.00'))->toBeTrue();
});

it('stays exempt for a zero or empty order', function (): void {
    expect(GermanVat::requiresBillingAddress('0.00'))->toBeFalse();
});
