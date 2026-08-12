<?php

declare(strict_types=1);

namespace Konventix\Messaging\Enums;

/**
 * Delivery channels Messaging knows how to send on. Only Mail exists today;
 * in-app and push are Backlog (see TODO.md and SPEC.md's channel abstraction
 * section). Kept as a backed enum so the scalar that rides in event payloads
 * and the driver-selection key are the same value everywhere.
 */
enum Channel: string
{
    case Mail = 'mail';
}
