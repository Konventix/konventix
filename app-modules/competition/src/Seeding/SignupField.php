<?php

declare(strict_types=1);

namespace Konventix\Competition\Seeding;

use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;

/**
 * Who is actually in a competition's field (design §6, §7.5).
 *
 * One definition, because two would disagree the first time a rule changed:
 * `GenerateBracket` asks this to draw a bracket and the signup seed sources
 * ask it to order one, and "the field" has to mean the same thing to both.
 *
 * **Which entrants depends on the check-in mode.** A competition that checks
 * its teams in seeds the checked-in ones only, and a no-show is simply not
 * drawn. With `none` there is nothing to check in and `registered` is the
 * whole field. `checked_in` is in both lists — a desk that ticked people off
 * under `none` has not thereby excluded the rest.
 *
 * `incomplete` is in neither: an incomplete roster holds a slot against the
 * participant limit but is never seeded, and `start` refuses while one exists
 * (§5.4).
 *
 * Every query escapes `BrandScope` and states the brand from the stage: the
 * desk runs on the operator host, where the scope hardens to zero rows
 * (ADR-0029, .ai/rules/app-modules-src.md).
 */
final class SignupField
{
    /**
     * The field in its current order: seeded entrants first, in seed order,
     * then the unseeded behind them oldest first. A bracket must be
     * reproducible from the same field.
     *
     * @return list<string>
     */
    public function entrantIds(Stage $stage): array
    {
        return array_values(array_map(strval(...), Entrant::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('competition_id', $stage->competition_id)
            ->whereIn('state', $this->seedableStates($stage))
            ->orderByRaw('seed IS NULL')
            ->orderBy('seed')
            ->orderBy('created_at')
            ->pluck('id')
            ->all()));
    }

    /**
     * The entrant states this stage may seed.
     *
     * A stage whose competition has gone missing falls back to the wider
     * list: generation is already refused elsewhere for a competition that
     * does not exist, and a silent empty bracket is the worse failure.
     *
     * @return list<string>
     */
    public function seedableStates(Stage $stage): array
    {
        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($stage->competition_id)->first();

        if ($competition !== null && $competition->check_in_mode->requiresCheckIn()) {
            return [EntrantState::CheckedIn->value];
        }

        return [EntrantState::Registered->value, EntrantState::CheckedIn->value];
    }
}
