<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Closure;
use Database\Seeders\Demo\DemoAdmissionSeeder;
use Database\Seeders\Demo\DemoCommerceSeeder;
use Database\Seeders\Demo\DemoCompetitionSeeder;
use Database\Seeders\Demo\DemoComplianceSeeder;
use Database\Seeders\Demo\DemoContentSeeder;
use Database\Seeders\Demo\DemoEventSeeder;
use Database\Seeders\Demo\DemoMediaSeeder;
use Database\Seeders\Demo\DemoMessagingSeeder;
use Database\Seeders\Demo\DemoPlatformSeeder;
use Database\Seeders\Demo\DemoRegistrationSeeder;
use Database\Seeders\Demo\DemoResourcesSeeder;
use Database\Seeders\Demo\DemoStaffingSeeder;
use Database\Seeders\Demo\DemoStaticPageSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Database\Seeders\Demo\EventArchetype;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event as EventDispatcher;
use Illuminate\Support\Str;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Operator;
use Konventix\Resources\Models\ResourceType;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\text;

use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a realistic demo dataset covering every module that has a domain
 * behind it. `--default` takes the medium baseline (3 brands, ~100 users,
 * 2 events per brand) without asking; without it, Laravel Prompts walks
 * through the dimensions interactively.
 *
 * This command is the composition root and nothing else. Cross-module
 * composition is sanctioned here and only here (like `DatabaseSeeder`) —
 * modules may not import each other's models (ADR-0004), the host may — so
 * each module's slice lives in its own class under
 * `database/seeders/Demo/` and this file decides the order they run in.
 *
 * That order is not arbitrary:
 *
 * 1. Module activation before anything else, because a listener behind a
 *    Pennant gate does nothing for a brand the feature is off for (ADR-0003).
 * 2. Accounts before events: every account emits `UserRegistered`, and three
 *    modules build their read model of people from it alone.
 * 3. Media before events, because badge templates reference it by ULID.
 * 4. Resource types before registrations, so a confirmed exhibitor's booth
 *    claim has something to be fulfilled against.
 * 5. Registrations before admission, staffing and competition, all of which
 *    hang off people who actually hold one.
 *
 * Each brand is seeded as one of two archetypes (`EventArchetype`) — a LAN
 * party or a trade fair, the two shapes the definition of done names. Only
 * configuration differs between them; if a slice below ever needs a branch
 * of its own, that is a modelling failure to fix in the module rather than
 * here.
 *
 * Everything runs through the production Actions rather than writing rows,
 * so the demo data is the output of the same code paths a real event uses.
 */
class SeedDemoDataCommand extends Command
{
    protected $signature = 'konventix:seed
        {--default : Seed the default demo set without asking questions}
        {--fresh : Wipe and re-migrate the database first}';

    protected $description = 'Seed demo data across every module — interactive unless --default';

    private DemoPlatformSeeder $platform;

    private DemoUserSeeder $users;

    private DemoMediaSeeder $media;

    private DemoEventSeeder $events;

    private DemoRegistrationSeeder $registrations;

    private DemoCommerceSeeder $commerce;

    private DemoAdmissionSeeder $admission;

    private DemoResourcesSeeder $resources;

    private DemoStaffingSeeder $staffing;

    private DemoCompetitionSeeder $competition;

    private DemoContentSeeder $content;

    private DemoComplianceSeeder $compliance;

    private DemoMessagingSeeder $messaging;

    private DemoStaticPageSeeder $staticPages;

    private BrandContext $brandContext;

    /**
     * The brand currently being seeded, re-pinned after every queued job (see
     * `keepBrandPinnedAcrossJobs()`).
     */
    private ?Brand $currentBrand = null;

    public function handle(): int
    {
        intro('Konventix demo seed');

        $this->resolveSeeders();
        $this->keepBrandPinnedAcrossJobs();

        if ($this->option('fresh') && ! $this->wipeDatabase()) {
            return self::FAILURE;
        }

        [$brandCount, $usersPerBrand, $eventsPerBrand, $registrationsPerEvent] = $this->dimensions();

        // Baseline: capabilities, dev brand, operator + admin login.
        Artisan::call('db:seed', ['--force' => true], $this->output);

        $this->linkPublicStorage();

        $operator = Operator::query()->firstWhere('name', 'Konventix Dev Operator');
        $admin = User::query()->firstWhere('email', 'admin@konventix.test');

        $brands = $this->seedBrands($brandCount, $operator);

        foreach ($brands->values() as $index => $brand) {
            $this->seedBrand(
                $brand,
                $index,
                EventArchetype::forBrandSlug($brand->slug, $index),
                $usersPerBrand,
                $eventsPerBrand,
                $registrationsPerEvent,
                $admin,
                $operator,
            );
        }

        // Console favorites are not brand-owned (ADR-0029), so they are
        // seeded once, after every brand exists to point at.
        $this->platform->seedFavorites($admin);

        $this->currentBrand = null;
        $this->brandContext->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        outro(sprintf(
            'Done. Log in as admin@konventix.test / password on http://%s — brands: %s',
            Operator::query()->value('admin_host') ?? 'admin.localhost',
            $brands->map(fn (Brand $brand): string => $brand->domains()->value('host') ?? $brand->slug)->implode(', '),
        ));

        return self::SUCCESS;
    }

    /**
     * Resolved when the command runs, not when it is constructed. A console
     * command object is built during kernel bootstrap, and a slice that
     * captured a scoped service at that moment — `DiskResolver` memoizes the
     * filesystems it builds — would keep serving the pre-bootstrap one for the
     * whole run, past any later swap (a test's faked disk, for instance).
     */
    private function resolveSeeders(): void
    {
        $this->platform = app(DemoPlatformSeeder::class);
        $this->users = app(DemoUserSeeder::class);
        $this->media = app(DemoMediaSeeder::class);
        $this->events = app(DemoEventSeeder::class);
        $this->registrations = app(DemoRegistrationSeeder::class);
        $this->commerce = app(DemoCommerceSeeder::class);
        $this->admission = app(DemoAdmissionSeeder::class);
        $this->resources = app(DemoResourcesSeeder::class);
        $this->staffing = app(DemoStaffingSeeder::class);
        $this->competition = app(DemoCompetitionSeeder::class);
        $this->content = app(DemoContentSeeder::class);
        $this->compliance = app(DemoComplianceSeeder::class);
        $this->messaging = app(DemoMessagingSeeder::class);
        $this->staticPages = app(DemoStaticPageSeeder::class);
        $this->brandContext = app(BrandContext::class);
    }

    /**
     * Seeded media is served straight off the `public/storage` symlink, and a
     * checkout that never ran `storage:link` has none — every image then 403s
     * rather than 404s, because the `local` disk's signed `storage/{path}`
     * route answers instead and rejects the unsigned request. Guarded on the
     * link's absence: `storage:link` reports an existing link as an error,
     * which would look like a failure on every re-run.
     */
    private function linkPublicStorage(): void
    {
        if (file_exists(public_path('storage'))) {
            return;
        }

        // Relative, so the link resolves both inside the container and on the
        // host that mounts the project directory.
        Artisan::call('storage:link', ['--relative' => true], $this->output);
    }

    private function wipeDatabase(): bool
    {
        if (! $this->option('default') && ! confirm('Wipe the database and re-migrate?', default: false)) {
            return false;
        }

        Artisan::call('migrate:fresh', ['--force' => true], $this->output);

        return true;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function dimensions(): array
    {
        if ($this->option('default')) {
            return [3, 100, 2, 15];
        }

        $ask = fn (string $label, int $default): int => max(0, (int) text(
            label: $label,
            default: (string) $default,
            validate: fn (string $value): ?string => ctype_digit($value) ? null : 'Enter a whole number.',
        ));

        return [
            max(1, $ask('How many brands (besides the dev brand)?', 3)),
            $ask('How many users per brand?', 100),
            $ask('How many events per brand?', 2),
            $ask('How many registrations per event?', 15),
        ];
    }

    /**
     * @return Collection<int, Brand>
     */
    private function seedBrands(int $count, ?Operator $operator): Collection
    {
        $names = ['SXLan', 'Xpertise', 'Nordcon', 'Playa', 'Brickfest', 'Synthwave', 'Quizzity', 'Meeple'];

        return collect(range(1, $count))->map(function (int $index) use ($names, $operator): Brand {
            $name = $names[$index - 1] ?? 'Brand '.$index;
            $slug = Str::slug($name);

            // Settling money needs a legal seller identity: OperatorSettings
            // refuses invoice issuance for a brand without an operator — and
            // since slice 15b the database refuses the brand outright, so the
            // operator is set before the insert rather than associated after.
            // Through the relation, because `operator_id` is deliberately not
            // mass-assignable.
            $brand = Brand::query()->firstOrNew(['slug' => $slug]);

            if (! $brand->exists) {
                $brand->fill(['name' => $name, 'theme_tokens' => []]);
                $brand->operator()->associate($operator);
                $brand->save();
            }

            $brand->domains()->firstOrCreate(
                ['host' => $slug.'.localhost'],
                ['is_primary' => true],
            );

            return $brand;
        });
    }

    private function seedBrand(
        Brand $brand,
        int $index,
        EventArchetype $archetype,
        int $users,
        int $events,
        int $registrationsPerEvent,
        ?User $admin,
        ?Operator $operator,
    ): void {
        info("Seeding {$brand->name} ({$archetype->label()})…");

        // First: theme and module activation. A gated module's listeners
        // short-circuit for a brand the feature is off for, so switching them
        // on afterwards would leave every projection behind them empty.
        $this->inBrand($brand, fn () => $this->platform->handle($brand, $archetype));

        $members = $this->inBrand($brand, fn (): Collection => $this->seedUsers($brand, $users));

        $this->inBrand($brand, fn () => $this->platform->seedRoles($brand, $members));

        $media = $this->inBrand($brand, fn (): array => $this->media->handle($brand));

        $this->inBrand($brand, fn () => $this->admission->seedSigningKey($brand));
        $this->inBrand($brand, fn () => $this->commerce->seedSettlementAccounts());
        $this->inBrand($brand, fn () => $this->commerce->seedBookkeepingSettings($operator));

        // The first brand runs its own SMTP relay, the rest fall back to the
        // application mailer (ADR-0028) — both paths visible in one seed.
        $this->inBrand($brand, fn () => $this->messaging->handle($brand, $members, withOwnTransport: $index === 0));

        $seededEvents = $this->inBrand($brand, fn (): Collection => $this->events->handle($brand, $events, $archetype));

        foreach ($seededEvents as $event) {
            $this->seedEvent($brand, $event, $archetype, $members, $registrationsPerEvent, $media, $admin);
        }

        $firstEvent = $seededEvents->first();

        $this->inBrand($brand, fn () => $this->compliance->handle($brand, $members));
        $this->inBrand($brand, fn () => $this->content->handle($brand, $firstEvent, $members, $archetype));
        $this->inBrand($brand, fn () => $this->staticPages->handle($brand, $firstEvent, $media));
    }

    /**
     * Runs one slice with the brand asserted in context.
     *
     * Re-asserted before *every* slice rather than once per brand, and that is
     * the whole reason this helper exists: any slice may dispatch a queued job
     * — a thumbnail, an invoice document, a notification fan-out — and on a
     * sync queue `PropagatesBrandContextToQueue` clears the context the moment
     * that job finishes. It has to: a long-lived worker may not leak one job's
     * brand into the next. The next brand-owned create would then fail for
     * want of a brand, and which slice happens to dispatch a job is not
     * something the caller should have to know.
     *
     * The permission team id rides along for the same reason (ADR-0013).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $slice
     * @return TReturn
     */
    private function inBrand(Brand $brand, Closure $slice): mixed
    {
        $this->currentBrand = $brand;

        $this->pinBrand($brand);

        return $slice();
    }

    /**
     * A seeding run is a console process, not a queue worker — but on a `sync`
     * queue it looks like one to `PropagatesBrandContextToQueue`, which clears
     * the brand context the moment a job finishes so a long-lived worker never
     * leaks one job's brand into the next. Correct for a worker; fatal here,
     * because the seed is still mid-brand and the next brand-owned create
     * would have nothing to be stamped with.
     *
     * So the seed restores what it is doing after each job. This listener is
     * registered after Platform's, therefore runs after it, and re-pins the
     * brand the seed is currently working on. Nothing else in the process
     * dispatches jobs, and the listener dies with the command.
     */
    private function keepBrandPinnedAcrossJobs(): void
    {
        EventDispatcher::listen(JobProcessed::class, function (): void {
            if ($this->currentBrand !== null) {
                $this->pinBrand($this->currentBrand);
            }
        });
    }

    /**
     * The brand for scoping (ADR-0010) and its permission team id (ADR-0013),
     * which travel together everywhere.
     */
    private function pinBrand(Brand $brand): void
    {
        $this->brandContext->set($brand);
        app(PermissionRegistrar::class)->setPermissionsTeamId($brand->getKey());
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  array<string, MediaItem>  $media
     */
    private function seedEvent(
        Brand $brand,
        Event $event,
        EventArchetype $archetype,
        Collection $members,
        int $registrationsPerEvent,
        array $media,
        ?User $admin,
    ): void {
        // Before registrations: a confirmed exhibitor's booth claim fails
        // soft when the resource type does not exist yet, and the exhibitor
        // would silently end up with no booth.
        $boothType = $this->inBrand($brand, fn (): ResourceType => $this->resources->seedCatalogue($event, $archetype));

        $confirmed = $this->inBrand($brand, fn (): Collection => $this->registrations->handle(
            $event,
            $members,
            $registrationsPerEvent,
            $boothType,
            $admin,
            $archetype,
        ));

        $this->inBrand($brand, fn () => $this->commerce->handle($brand, $event, $members, $registrationsPerEvent));

        $this->inBrand($brand, fn () => $this->admission->handle($event, $media));
        $this->inBrand($brand, fn () => $this->staffing->handle($event, $members, $archetype));

        // A trade fair runs no tournaments, and the module is off for it
        // (ADR-0003) — seeding one anyway would produce rows behind a closed
        // gate that no screen in the console can reach.
        if ($archetype->runsCompetition()) {
            $this->inBrand($brand, fn () => $this->competition->handle($event, $confirmed));
        }

        $this->inBrand($brand, fn () => $this->resources->seedFloorPlanBookings(
            $event,
            $confirmed->pluck('id'),
            $archetype,
        ));
    }

    /**
     * The console half of user seeding. The bar lives here rather than in the
     * seeder — a seeder that printed progress bars could not be called from
     * anywhere but a command — and is sized from what the brand is actually
     * missing, so a re-run that creates nobody shows no bar at all.
     *
     * @return Collection<int, User>
     */
    private function seedUsers(Brand $brand, int $target): Collection
    {
        $pending = $this->users->pendingCount($target);

        if ($pending === 0) {
            return $this->users->handle($brand, $target);
        }

        $bar = progress(label: "Users for {$brand->name}", steps: $pending);
        $bar->start();

        $members = $this->users->handle($brand, $target, fn () => $bar->advance());

        $bar->finish();

        return $members;
    }
}
