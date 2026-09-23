<?php

declare(strict_types=1);

namespace Konventix\Competition\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Konventix\Competition\Actions\AnnounceSignupOpened;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Providers\CompetitionServiceProvider;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;

/**
 * The signup clock of design §4: "`SignupClosed` — the organizer has closed
 * signups, explicitly, or because `signup_closes_at` passed and the scheduler
 * closed it", and "`SignupOpened` is emitted when a `Published`
 * competition's window opens (scheduler tick)". Ruled in Task 1's review,
 * built in the final review's fix wave B.
 *
 * Every `Published` competition, across brands:
 *
 * 1. **Close.** `signup_closes_at <= now` → `CloseSignup`, the console's own
 *    Action, so `SignupClosed` goes out exactly as it does for a click. The
 *    edge matches `Competition::signupIsOpen()`: signup is open while
 *    `now < signup_closes_at`, so at `signup_closes_at` itself it is closed
 *    and the tick closes it.
 * 2. **Announce.** Signup open now and not yet announced for this window →
 *    `AnnounceSignupOpened`. Open means `signup_opens_at <= now` (again
 *    `signupIsOpen()`'s edge) or no opening date at all. Closing runs first,
 *    so a window that opened and closed between two ticks is closed and never
 *    announced as open.
 *
 * **Gated per brand** (ADR-0003). The scheduler resolves no brand, so the
 * question is `ModuleGate::isActiveForBrand()` with each competition's own
 * brand: a brand with `competition` off keeps its rows exactly as they are,
 * the way its listeners short-circuit. Each Action then runs with that brand
 * in `BrandContext` (Resources' `ExpireResourceHolds` does the same), so a
 * future listener reading the ambient brand finds it.
 *
 * Every minute with `withoutOverlapping()` in
 * `CompetitionServiceProvider::registerSchedule()`. Both Actions re-read the
 * row under its lock and refuse or skip what is no longer due, so a run that
 * overlaps a console click changes nothing twice.
 */
final class SignupWindowTickCommand extends Command
{
    protected $signature = 'competition:signup-tick';

    protected $description = 'Close signup for competitions whose signup window has ended, and announce the ones whose window has opened.';

    public function handle(CloseSignup $close, AnnounceSignupOpened $announce, ModuleGate $gate, BrandContext $brands): int
    {
        $now = CarbonImmutable::now();

        /** @var array<string, bool> $active brand ULID => module on */
        $active = [];
        $isActive = static function (string $brandId) use (&$active, $gate): bool {
            return $active[$brandId] ??= $gate->isActiveForBrand(CompetitionServiceProvider::moduleName(), $brandId);
        };

        $closed = 0;

        foreach ($this->dueToClose($now) as $competition) {
            if (! $isActive($competition->brand_id)) {
                continue;
            }

            try {
                $this->asBrand($brands, $competition, fn (): Competition => $close->handle($competition));
                $closed++;
            } catch (IllegalCompetitionStateTransitionException) {
                // Moved on under the lock (a console click beat the tick).
            }
        }

        $announced = 0;

        foreach ($this->mayBeDueToAnnounce($now) as $competition) {
            if (! $isActive($competition->brand_id)) {
                continue;
            }

            if ($this->asBrand($brands, $competition, fn (): bool => $announce->handle($competition, $now))) {
                $announced++;
            }
        }

        $this->info("Closed signup for {$closed} competition(s), announced {$announced} open signup(s).");

        return self::SUCCESS;
    }

    /**
     * @return iterable<Competition>
     */
    private function dueToClose(CarbonInterface $now): iterable
    {
        return Competition::withoutBrandScope()
            ->where('status', CompetitionStatus::Published)
            ->whereNotNull('signup_closes_at')
            ->where('signup_closes_at', '<=', $now)
            ->lazyById();
    }

    /**
     * A superset of the due ones, narrowed in SQL to an open window; whether
     * this window was announced already is `AnnounceSignupOpened::isDue()`'s
     * answer, asked again under the lock.
     *
     * @return iterable<Competition>
     */
    private function mayBeDueToAnnounce(CarbonInterface $now): iterable
    {
        return Competition::withoutBrandScope()
            ->where('status', CompetitionStatus::Published)
            ->where(fn (Builder $query) => $query->whereNull('signup_opens_at')->orWhere('signup_opens_at', '<=', $now))
            ->where(fn (Builder $query) => $query->whereNull('signup_closes_at')->orWhere('signup_closes_at', '>', $now))
            ->where(fn (Builder $query) => $query->whereNull('signup_opened_at')->orWhereColumn('signup_opened_at', '<', 'signup_opens_at'))
            ->lazyById();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asBrand(BrandContext $brands, Competition $competition, callable $callback): mixed
    {
        $brand = Brand::query()->find($competition->brand_id);

        return $brand === null ? $callback() : $brands->withBrand($brand, $callback);
    }
}
