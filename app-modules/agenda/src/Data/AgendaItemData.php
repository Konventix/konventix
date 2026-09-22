<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Konventix\Agenda\Models\AgendaItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One programme entry as the editor posts it (design §4.2): the single
 * source of validation, Inertia props and generated TypeScript for an
 * agenda item (ADR-0009).
 *
 * The two instants are ISO-8601 strings on the way in and out, never
 * Carbon objects: this shape crosses the wire to the editor, and
 * `SaveAgendaItem` is the one place that turns a string into an instant
 * (and anchors it to UTC while doing so).
 *
 * `state` is deliberately absent. A draft becomes published through
 * `PublishAgendaItem` and is called off through `CancelAgendaItem`,
 * because both are events somebody outside this module may need to hear
 * about — a form field that could silently set `cancelled` would emit
 * nothing.
 */
#[TypeScript]
final class AgendaItemData extends Data
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $trackId,
        public readonly ?string $locationId,
        public readonly ?string $locationNote,
        public readonly ?string $speaker,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'trackId' => ['nullable', 'ulid'],
            'locationId' => ['nullable', 'ulid'],
            'locationNote' => ['nullable', 'string', 'max:120'],
            'speaker' => ['nullable', 'string', 'max:120'],
            // D-AGD-04: at least one of the two, said in the form's words
            // here and guaranteed by the `agenda_items_has_a_time` check
            // constraint there. `required_without` on both sides is what
            // makes "neither" the only refused combination.
            //
            // `after:startsAt` does not spoil the end-only shape ("bis
            // 08:00"). Laravel still evaluates the rule — it is not
            // skipped, the way the docs' wording suggests — but
            // `compareDates()` resolves an absent `startsAt` to no
            // timestamp at all and compares against null, which any real
            // instant is "after". Verified against
            // `ValidatesAttributes::compareDates()` and by running all
            // five shapes through the validator.
            'startsAt' => ['nullable', 'date', 'required_without:endsAt'],
            'endsAt' => ['nullable', 'date', 'required_without:startsAt', 'after:startsAt'],
        ];
    }

    public static function fromModel(AgendaItem $item): self
    {
        return new self(
            id: $item->id,
            title: $item->title,
            description: $item->description,
            trackId: $item->track_id,
            locationId: $item->location_id,
            locationNote: $item->location_note,
            speaker: $item->speaker,
            startsAt: $item->starts_at?->toIso8601String(),
            endsAt: $item->ends_at?->toIso8601String(),
        );
    }
}
