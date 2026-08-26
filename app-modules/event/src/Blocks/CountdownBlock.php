<?php

declare(strict_types=1);

namespace Konventix\Event\Blocks;

use Konventix\Event\Data\Blocks\CountdownBlockData;
use Konventix\Event\Data\Blocks\CountdownResolvedData;
use Konventix\Event\Models\Event;
use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Blocks\ResolvesBlockProps;

/**
 * Event's countdown static-page block, ticking down to an event's start.
 *
 * The stored payload is the event ULID plus an optional label; the target
 * instant is computed here via `startsAtInstant()` (ADR-0027) — never a raw
 * `Carbon::parse()` of the wall-clock column, and never in the frontend.
 */
final class CountdownBlock implements BlockType, HasBlockEditor, ResolvesBlockProps
{
    public function key(): string
    {
        return 'countdown';
    }

    public function module(): string
    {
        return 'event';
    }

    public function component(): string
    {
        return 'event/blocks/CountdownBlock';
    }

    public function dataClass(): string
    {
        return CountdownBlockData::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $payload = CountdownBlockData::from($data);

        // BelongsToBrand's global scope restricts this to the current
        // brand's events; a cross-brand or stale event ULID resolves null.
        $event = Event::query()->find($payload->eventId);

        return new CountdownResolvedData(
            found: $event !== null,
            label: $payload->label,
            eventName: $event?->name,
            targetIso: $event?->startsAtInstant()->toIso8601String(),
        )->toArray();
    }

    public function label(): string
    {
        return 'Countdown';
    }

    public function editorComponent(): string
    {
        return 'event/blocks/editors/CountdownEditor';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(): array
    {
        return ['eventId' => '', 'label' => null];
    }
}
