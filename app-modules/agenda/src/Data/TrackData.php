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
 * and uses that to tell "renamed" from "new".
 *
 * `distinct` on the name is the *payload's* half of `agenda_tracks`'
 * unique(event_id, name). The controller lifts these rules onto
 * `tracks.*.`, so the rule compares the submitted set against itself and
 * fails on the offending row's own indexed key, which the editor renders
 * beside that row. Without it two columns typed "Bühne" reach Postgres,
 * raise `SQLSTATE[23505]`, and roll the surrounding transaction back —
 * losing every other rename and reorder in the same submit, with no field
 * to point at. `ignore_case`, because two places that differ only in case
 * are a typo rather than two places; the index is stricter about nothing
 * and laxer about that one thing. The *stored* set is still the table's
 * index to guard: that needs the event this payload belongs to, which the
 * Action knows and the Data does not.
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
            'name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
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
