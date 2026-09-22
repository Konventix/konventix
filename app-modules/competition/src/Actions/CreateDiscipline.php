<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Data\DisciplineData;
use Konventix\Competition\Models\Discipline;

/**
 * Creates a discipline from the console (design §9, ADR-0024/ADR-0062).
 *
 * The brand comes from the request, not from context: the admin host
 * resolves none (ADR-0029), so `brand_id` is force-filled before the save
 * and `BelongsToBrand`'s creating hook finds it already set.
 */
final class CreateDiscipline
{
    public function handle(string $brandId, DisciplineData $data): Discipline
    {
        return DB::transaction(function () use ($brandId, $data): Discipline {
            $this->guardSlug($brandId, $data->slug);

            $discipline = new Discipline;
            $discipline->forceFill([
                'brand_id' => $brandId,
                'kind' => $data->kind,
                'name' => $data->name,
                'slug' => $data->slug,
                'game_id' => $data->gameId,
                'team_size' => $data->teamSize,
                'avg_match_minutes' => $data->avgMatchMinutes,
                'allow_draws' => $data->allowDraws,
            ])->save();

            return $discipline;
        });
    }

    private function guardSlug(string $brandId, string $slug): void
    {
        $taken = Discipline::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('This brand already has a discipline with that slug.'),
            ]);
        }
    }
}
