<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\RegenerateBracket;
use Konventix\Competition\Actions\ResetStage;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Actions\ScheduleRound;
use Konventix\Competition\Actions\SeedStage;
use Konventix\Competition\Data\ScheduleMatchData;
use Konventix\Competition\Data\ScheduleRoundData;
use Konventix\Competition\Data\SeedOrderData;
use Konventix\Competition\Desk\TournamentDesk;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Http\Controllers\Admin\Concerns\AnswersTheDesk;
use Konventix\Competition\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Seeding\ManualSeedSource;

/**
 * The tournament desk (design §9, D-CMP-20, D-CMP-21): the page, and the
 * half of it that arranges who plays whom and when — seed order, generate,
 * regenerate and reset, and the match times. Results live next door in
 * `DeskResultController`.
 *
 * Capabilities are §9's four: the page opens for anybody holding one of
 * them, and each write asks for its own. Drawing the bracket is
 * `ManageSchedule` — §9 gives the desk page exactly four capabilities, and
 * seed order and the draw are the arrangement of the tournament that the
 * schedule is the other half of; neither `CorrectResults` nor
 * `ManageEntrants` (the admin's two, §11) is about it.
 *
 * Every write is an Action. Every row this is handed must belong to one of
 * the admin host's own brands (`ScopesToOperator`), and every query escapes
 * `BrandScope` and names the brand, because the admin host resolves none
 * (ADR-0029). The capability check is always the first line, so a console
 * account without it reads 403 whatever it asks for.
 *
 * Console copy is English.
 */
class TournamentDeskController
{
    use AnswersTheDesk;
    use ScopesToOperator;

    /** The four capabilities §9 names for the desk page. */
    private const array DESK_POWERS = [
        Permission::CheckInEntrants,
        Permission::RecordResults,
        Permission::CorrectResults,
        Permission::ManageSchedule,
    ];

    public function show(string $competition, TournamentDesk $desk): Response
    {
        abort_unless(Gate::any(array_map(fn (Permission $power): string => $power->value, self::DESK_POWERS)), 403);

        /** @var Competition $model */
        $model = Competition::withoutBrandScope()->findOrFail($competition);

        $this->ensureOperatorOwns($model->brand_id);

        return Inertia::render('admin/competitions/desk/Desk', [
            'desk' => $desk->for($model),
        ]);
    }

    /**
     * Apply the order the desk dragged (§7.5). Anybody the list leaves out is
     * appended behind it by `ManualSeedSource`.
     */
    public function seed(Request $request, string $stage, SeedStage $seed): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);
        $data = SeedOrderData::validateAndCreate($request->all());

        $this->attempt(fn () => $seed->handle($model, new ManualSeedSource(array_values($data->entrantIds))));

        return $this->done(__('Seed order saved.'));
    }

    /** A fresh random order — the default seed source (D-CMP-20). */
    public function shuffle(string $stage, SeedStage $seed): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);

        $this->attempt(fn () => $seed->handle($model));

        return $this->done(__('Field shuffled.'));
    }

    public function generate(string $stage, GenerateBracket $generate): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);

        $this->attempt(fn () => $generate->handle($model));

        return $this->done(__('Bracket generated.'));
    }

    /** Reset and draw again from the current seed order, in one step (§7.5). */
    public function regenerate(string $stage, RegenerateBracket $regenerate): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);

        $this->attempt(fn () => $regenerate->handle($model));

        return $this->done(__('Bracket drawn again.'));
    }

    /** Back to undrawn; allowed until the first recorded result (§7.5). */
    public function reset(string $stage, ResetStage $reset): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);

        $this->attempt(fn () => $reset->handle($model));

        return $this->done(__('Bracket reset.'));
    }

    /** "Schedule all at" for one round (D-CMP-21). */
    public function scheduleRound(Request $request, string $stage, ScheduleRound $schedule): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        $model = $this->findStage($stage);
        $data = ScheduleRoundData::validateAndCreate($request->all());

        $moved = $this->attempt(fn (): int => $schedule->handle(
            $model,
            $data->bracket,
            $data->round,
            $data->scheduledAtInstant(),
            $this->actorId($request),
        ));

        return $this->done(trans_choice('{0} No matches scheduled.|{1} :count match scheduled.|[2,*] :count matches scheduled.', $moved));
    }

    /** One match's `scheduled_at` (D-CMP-21). */
    public function scheduleMatch(Request $request, string $match, ScheduleMatch $schedule): RedirectResponse
    {
        Gate::authorize(Permission::ManageSchedule->value);

        /** @var CompetitionMatch $model */
        $model = CompetitionMatch::withoutBrandScope()->findOrFail($match);

        $this->ensureOperatorOwns($model->brand_id);

        $data = ScheduleMatchData::validateAndCreate($request->all());

        $this->attempt(fn () => $schedule->handle(
            $model,
            $data->scheduledAtInstant(),
            scheduledByUserId: $this->actorId($request),
        ));

        return $this->done(__('Match scheduled.'));
    }

    private function findStage(string $stage): Stage
    {
        /** @var Stage $model */
        $model = Stage::withoutBrandScope()->findOrFail($stage);

        $this->ensureOperatorOwns($model->brand_id);

        return $model;
    }
}
