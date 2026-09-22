<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Data\CompetitionData;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;

/**
 * What makes a competition *this* competition: a slug unique within its
 * event (the `competitions` unique index; refused here so the operator sees
 * a field error rather than a 500 from the constraint), a discipline of the
 * same brand, and a first-stage format the `FormatRegistry` can actually
 * resolve — a stage carrying a format nothing is registered for cannot be
 * generated, and the failure would only surface at the desk.
 *
 * Both Actions run on the admin host with no brand in context, so every
 * query escapes the scope explicitly and filters by the brand id it was
 * handed (ADR-0029, .ai/rules/app-modules-src.md).
 */
trait GuardsCompetitionIdentity
{
    private function guardSlug(string $brandId, string $eventId, string $slug, ?string $exceptId = null): void
    {
        $taken = Competition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('slug', $slug)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('Another competition of this event already uses this slug.'),
            ]);
        }
    }

    private function guardDiscipline(string $brandId, string $disciplineId): void
    {
        $known = Discipline::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereKey($disciplineId)
            ->exists();

        if (! $known) {
            throw ValidationException::withMessages([
                'disciplineId' => __('That discipline does not belong to this brand.'),
            ]);
        }
    }

    private function guardStageFormat(CompetitionData $data): void
    {
        if (! app(FormatRegistry::class)->has($data->stageFormat)) {
            throw ValidationException::withMessages([
                'stageFormat' => __('No bracket format is registered under that name.'),
            ]);
        }
    }
}
