<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use InvalidArgumentException;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Events\RecipientSuppressionAdded;
use Konventix\Messaging\Models\Suppression;

/**
 * Adds a live suppression for a brand (ADR-0041). Idempotent: an existing
 * live entry for the same key is returned untouched — a second hard bounce
 * is not a second fact.
 */
final class AddSuppression
{
    public function handle(
        string $brandId,
        Channel $channel,
        ?string $email,
        ?string $recipientId,
        string $reasonCode,
    ): Suppression {
        if (($email === null) === ($recipientId === null)) {
            throw new InvalidArgumentException(
                'A suppression binds to exactly one of email or recipient id (ADR-0041).',
            );
        }

        $existing = Suppression::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('channel', $channel)
            ->whereNull('lifted_at')
            ->when($email !== null, fn ($q) => $q->where('email', $email))
            ->when($recipientId !== null, fn ($q) => $q->where('recipient_id', $recipientId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $suppression = new Suppression([
            'channel' => $channel,
            'email' => $email,
            'recipient_id' => $recipientId,
            'reason_code' => $reasonCode,
            'suppressed_at' => now(),
        ]);
        // brand_id explicit, not ambient context (ADR-0010): suppressions
        // arrive from webhooks and the operator host.
        $suppression->forceFill(['brand_id' => $brandId])->save();

        event(new RecipientSuppressionAdded(
            suppressionId: $suppression->id,
            brandId: $brandId,
            recipientId: $recipientId,
            email: $email,
            channel: $channel->value,
            reasonCode: $reasonCode,
            suppressedAt: $suppression->suppressed_at->toIso8601String(),
        ));

        return $suppression;
    }
}
