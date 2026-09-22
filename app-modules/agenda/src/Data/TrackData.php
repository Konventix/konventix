<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Konventix\Agenda\Models\Track;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One column of the published grid, as the programme editor posts it
 * (design §4.1): the single source of validation, Inertia props and
 * generated TypeScript for a track (ADR-0009).
 *
 * `id` is null for a track the editor has just added and carries the ULID
 * of an existing row otherwise — `SaveTracks` takes the whole set at once
 * and uses that to tell "renamed" from "new". Uniqueness of the name per
 * event is the table's unique index, not a rule here: it needs the event
 * this set belongs to, which the Action knows and the Data does not.
 */
#[TypeScript]
final class TrackData extends Data
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly int $position,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'id' => ['nullable', 'ulid'],
            'name' => ['required', 'string', 'max:80'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromModel(Track $track): self
    {
        return new self(
            id: $track->id,
            name: $track->name,
            position: $track->position,
        );
    }
}
