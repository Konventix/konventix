<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Konventix\Agenda\Models\Location;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the programme's own places, as the editor posts it (design §4.3):
 * the single source of validation, Inertia props and generated TypeScript
 * for a location (ADR-0009).
 *
 * `venueId` optionally names the Event venue the place sits in, by ULID
 * and without a foreign key (ADR-0014) — display context only, so the rule
 * checks the shape and nothing else. `id` works exactly as it does on
 * `TrackData`: null for a place the editor has just added, the existing
 * ULID otherwise — and so does `distinct:ignore_case` on the name, which
 * refuses two places called "Halle 3" in one submit rather than letting
 * `agenda_locations`' unique(event_id, name) raise a 23505 that takes the
 * rest of the save down with it. See `TrackData` for the reasoning in full.
 */
#[TypeScript]
final class LocationData extends Data
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $venueId,
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
            'venueId' => ['nullable', 'ulid'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }

    public static function fromModel(Location $location): self
    {
        return new self(
            id: $location->id,
            name: $location->name,
            venueId: $location->venue_id,
            position: $location->position,
        );
    }
}
