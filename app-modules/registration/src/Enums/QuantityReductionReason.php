<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why an operator took units off a confirmed registration (D-REG-15).
 * Closed, like `Konventix\Commerce\Enums\RefundReason`, so the audit trail
 * and the reduction row can still be read as data years from now — the
 * operator's own words go in the free-text note beside it.
 *
 * `#[TypeScript]` because the console's reduce dialog renders this set as
 * its reason select; the labels are the page's, the values are this enum's
 * (ADR-0009).
 *
 * Distinct from `RefundReason` on purpose: that one says why *money* went
 * back and is the console's order vocabulary, this one says why the
 * *contractual act* shrank. The refund that follows a reduction carries
 * neither — its credit note is documented as a refund like every other.
 */
#[TypeScript]
enum QuantityReductionReason: string
{
    case RegistrantRequest = 'registrant-request';

    case DuplicateBooking = 'duplicate-booking';

    case OperatorCorrection = 'operator-correction';

    case Goodwill = 'goodwill';
}
