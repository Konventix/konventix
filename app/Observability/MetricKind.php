<?php

declare(strict_types=1);

namespace App\Observability;

enum MetricKind: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
