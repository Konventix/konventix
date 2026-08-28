<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * Wraps a plain array as a `Data` payload for the one caller that cannot
 * know a template's real typed payload class: `Actions\ResendMessage`
 * rebuilds a send from a delivery-log row, which kept only the payload's
 * rendered array (`SendMessage::log()` stores `$message->payload->toArray()`
 * — the original module-owned Data class is gone by the time a resend
 * runs, and Messaging may not import it anyway, ADR-0004). Both places that
 * ever read a request's `payload` — `TemplateRenderer::render()` and
 * `SendMessage::log()` — only ever call `toArray()`, so this is a safe
 * stand-in for exactly that path.
 *
 * Never construct this for an original send: `SendMessageRequest::$payload`
 * exists so a module hands over its own typed Data class (ADR-0009); this
 * class is the resend seam's escape hatch, not a general substitute.
 */
final class ArrayPayloadData extends Data
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
