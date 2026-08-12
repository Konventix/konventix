<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The net-vs-gross *display* decision, which hangs on the `RegistrationType`
 * and never on a global setting (ADR-0006, ADR-0017). Registration declares
 * it; Commerce applies it when presenting and documenting the order.
 */
enum PriceDisplay: string
{
    case Net = 'net';
    case Gross = 'gross';
}
