<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Konventix\Messaging\Events\RecipientSuppressionLifted;
use Konventix\Messaging\Models\Suppression;

/**
 * Lifts a suppression (ADR-0041): the row stays as history, deliveries
 * resume. Idempotent — lifting a lifted row changes nothing.
 */
final class LiftSuppression
{
    public function handle(Suppression $suppression): Suppression
    {
        if ($suppression->lifted_at !== null) {
            return $suppression;
        }

        $suppression->forceFill(['lifted_at' => now()])->save();

        event(new RecipientSuppressionLifted(
            suppressionId: $suppression->id,
            brandId: $suppression->brand_id,
            recipientId: $suppression->recipient_id,
            email: $suppression->email,
            channel: $suppression->channel->value,
            liftedAt: $suppression->lifted_at->toIso8601String(),
        ));

        return $suppression;
    }
}
