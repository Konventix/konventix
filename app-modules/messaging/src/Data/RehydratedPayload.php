<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * A payload rebuilt from a delivery-log row (ADR-0041 point 5): a held
 * envelope was never dispatched, so at flush time the typed original no
 * longer exists — only the array the log kept. This wrapper hands that
 * array back to the renderer exactly as the typed payload's `toArray()`
 * produced it, so the flushed message renders identically to an immediate
 * one.
 *
 * Internal to the flush path — the send API (`SendMessageRequest`) still
 * requires a real typed payload (ADR-0009).
 */
final class RehydratedPayload extends Data
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public readonly array $values) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
