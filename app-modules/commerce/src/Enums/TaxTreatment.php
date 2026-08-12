<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * The VAT treatment Commerce resolved for an order at settlement time
 * (ADR-0030) — the *treatment* is Commerce's call, on evidence Registration
 * never sees: the billing party's VAT ID and the operator's §19 UStG
 * election can both override the declared rate.
 *
 * `SmallBusiness` is a distinct invoice code path — no VAT line at all,
 * plus the statutory note — not a zero-rate line item (ADR-0030).
 */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';
    case SmallBusiness = 'small_business';
}
