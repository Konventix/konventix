<?php

declare(strict_types=1);

namespace Konventix\Messaging\Enums;

/**
 * Delivery channels Messaging knows how to send on (ADR-0041). Kept as a
 * backed enum so the scalar that rides in event payloads and the
 * driver-selection key are the same value everywhere.
 */
enum Channel: string
{
    case Mail = 'mail';
    case InApp = 'in_app';
    case Push = 'push';

    /**
     * Mail reaches account-less recipients by address; in-app and push are
     * meaningless without an account to attach the inbox row or the device
     * subscription to.
     */
    public function requiresRecipientId(): bool
    {
        return $this !== self::Mail;
    }
}
