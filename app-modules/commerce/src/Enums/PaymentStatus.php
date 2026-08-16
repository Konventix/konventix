<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * A payment attempt's lifecycle (ADR-0039). `Pending` is created when a
 * hosted checkout starts; every transition out of it happens exclusively in
 * a verified webhook handler — never on the return page, which only displays
 * whatever state the webhook has already written.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The provider closed the attempt without a payment — an abandoned
     * checkout session or a lapsed payment window. Distinct from `Failed`,
     * which says the payer tried and the payment did not go through.
     */
    case Expired = 'expired';
}
