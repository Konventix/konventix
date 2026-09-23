<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Data\DisciplineData;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;

/**
 * Edits a discipline (design §9, ADR-0024/ADR-0062).
 *
 * `team_size` freezes once a competition runs this discipline: it is the
 * ceiling a roster is checked against and the number that decides whether an
 * entrant is `incomplete` (§5.4), so lowering it mid-event would silently
 * re-classify teams that were complete when they entered. Rename it, change
 * the duration hint, flip draws — those are cosmetic by comparison.
 *
 * The linked-account requirement (§5.6) does **not** freeze, because
 * switching it changes nothing that exists. No entrant or roster is
 * touched, and the rule applies at the next entry or join.
 */
final class UpdateDiscipline
{
    public function handle(Discipline $discipline, DisciplineData $data): Discipline
    {
        return DB::transaction(function () use ($discipline, $data): Discipline {
            $this->guardSlug($discipline->brand_id, $data->slug, (string) $discipline->getKey());
            $this->guardTeamSize($discipline, $data);

            $discipline->forceFill([
                'kind' => $data->kind,
                'name' => $data->name,
                'slug' => $data->slug,
                'game_id' => $data->gameId,
                'team_size' => $data->teamSize,
                'avg_match_minutes' => $data->avgMatchMinutes,
                'allow_draws' => $data->allowDraws,
                'requires_linked_provider' => $data->requiresLinkedProvider,
            ])->save();

            return $discipline;
        });
    }

    private function guardSlug(string $brandId, string $slug, string $exceptId): void
    {
        $taken = Discipline::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->whereKeyNot($exceptId)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('This brand already has a discipline with that slug.'),
            ]);
        }
    }

    private function guardTeamSize(Discipline $discipline, DisciplineData $data): void
    {
        if ($discipline->team_size === $data->teamSize) {
            return;
        }

        $inUse = Competition::withoutBrandScope()
            ->where('brand_id', $discipline->brand_id)
            ->where('discipline_id', $discipline->getKey())
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'teamSize' => __('Competitions already run this discipline; its team size is frozen.'),
            ]);
        }
    }
}
