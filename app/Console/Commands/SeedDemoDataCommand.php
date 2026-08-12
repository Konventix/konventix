<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Actions\ConfirmRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\text;

use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a realistic demo dataset. `--default` takes the medium baseline
 * (3 brands, ~100 users, 2 events per brand) without asking; without it,
 * Laravel Prompts walks through the dimensions interactively.
 *
 * The host command is the sanctioned place for cross-module composition
 * (like DatabaseSeeder); registrations run through the real Actions so the
 * demo data exercises the same paths as production, admissions included.
 */
class SeedDemoDataCommand extends Command
{
    protected $signature = 'konventix:seed
        {--default : Seed the default demo set without asking questions}
        {--fresh : Wipe and re-migrate the database first}';

    protected $description = 'Seed demo data (brands, users, events, registrations) — interactive unless --default';

    public function handle(): int
    {
        intro('Konventix demo seed');

        if ($this->option('fresh') && ! $this->wipeDatabase()) {
            return self::FAILURE;
        }

        [$brandCount, $usersPerBrand, $eventsPerBrand, $registrationsPerEvent] = $this->dimensions();

        // Baseline: capabilities, dev brand, operator + admin login.
        Artisan::call('db:seed', ['--force' => true], $this->output);

        $brands = $this->seedBrands($brandCount);

        foreach ($brands as $brand) {
            $this->seedBrand($brand, $usersPerBrand, $eventsPerBrand, $registrationsPerEvent);
        }

        app(BrandContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        outro(sprintf(
            'Done. Log in as admin@konventix.test / password on http://%s — brands: %s',
            Operator::query()->value('admin_host') ?? 'admin.localhost',
            $brands->map(fn (Brand $brand): string => $brand->domains()->value('host') ?? $brand->slug)->implode(', '),
        ));

        return self::SUCCESS;
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
    private function seedBrands(int $count): Collection
    {
        $names = ['SXLan', 'Xpertise', 'Nordcon', 'Playa', 'Brickfest', 'Synthwave', 'Quizzity', 'Meeple'];

        return collect(range(1, $count))->map(function (int $index) use ($names): Brand {
            $name = $names[$index - 1] ?? 'Brand '.$index;
            $slug = Str::slug($name);

            $brand = Brand::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'theme_tokens' => []],
            );

            $brand->domains()->firstOrCreate(
                ['host' => $slug.'.localhost'],
                ['is_primary' => true],
            );

            return $brand;
        });
    }

    private function seedBrand(Brand $brand, int $users, int $events, int $registrationsPerEvent): void
    {
        info("Seeding {$brand->name}…");

        app(BrandContext::class)->set($brand);
        app(PermissionRegistrar::class)->setPermissionsTeamId($brand->getKey());

        $members = $this->seedUsers($brand, $users);

        $seededEvents = collect(range(1, max(1, $events)))
            ->map(fn (int $index): Event => $this->seedEvent($brand, $index, $members, $registrationsPerEvent));

        // The 'home' page's countdown/cards blocks want the brand's next
        // event, not just "an" event — falls back to whatever exists if
        // sorting by wall-clock start yields nothing (e.g. --default with
        // 0 events requested still seeds 1 via max(1, $events) above).
        $firstEvent = $seededEvents->sortBy(fn (Event $event): string => $event->starts_at->toDateTimeString())->first()
            ?? Event::query()->orderBy('starts_at')->first();

        $this->seedStaticPages($brand, $firstEvent);
    }

    /**
     * @return Collection<int, User>
     */
    private function seedUsers(Brand $brand, int $count): Collection
    {
        // Idempotent: top up to the requested membership count instead of
        // adding a fresh cohort on every run.
        $existing = BrandMembership::query()->with('user')->get();
        $count = max(0, $count - $existing->count());

        if ($count === 0) {
            return $existing->pluck('user')->filter()->values();
        }

        return collect(progress(
            label: "Users for {$brand->name}",
            steps: $count,
            callback: function (): User {
                $user = User::factory()->create();

                // Brand context is set: the scope constrains the lookup and
                // the BelongsToBrand hook stamps brand_id (ADR-0010).
                BrandMembership::query()->firstOrCreate(
                    ['user_id' => $user->getKey()],
                    ['first_seen_at' => now()->subDays(random_int(0, 365))],
                );

                return $user;
            },
        ))->merge($existing->pluck('user')->filter())->values();
    }

    private function seedStaticPages(Brand $brand, ?Event $firstEvent): void
    {
        foreach (['imprint' => 'Imprint', 'about' => 'About'] as $slug => $title) {
            // Scoped to the brand in context; idempotent on re-runs.
            StaticPage::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'published_at' => now(),
                    'blocks' => [
                        ['type' => 'text', 'data' => ['heading' => $title, 'body' => fake()->paragraphs(2, true)]],
                    ],
                ],
            );
        }

        // The '/' route (routes/web.php) renders this page through the block
        // pipeline when it exists; brands without one fall back to Welcome.
        StaticPage::query()->firstOrCreate(
            ['slug' => 'home'],
            [
                'title' => $brand->name,
                'published_at' => now(),
                'blocks' => $this->homeBlocks($brand, $firstEvent),
            ],
        );
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function homeBlocks(Brand $brand, ?Event $firstEvent): array
    {
        $blocks = [
            [
                'type' => 'hero',
                'data' => [
                    'heading' => $brand->name,
                    'subheading' => 'Event-agnostic event management, done right.',
                    'ctaLabel' => $firstEvent !== null ? 'View the event' : null,
                    'ctaUrl' => $firstEvent !== null ? '/events/'.$firstEvent->slug : null,
                    'imageUrl' => null,
                ],
            ],
        ];

        if ($firstEvent !== null) {
            $blocks[] = [
                'type' => 'countdown',
                'data' => ['eventId' => $firstEvent->getKey(), 'label' => 'Doors open in'],
            ];
        }

        $blocks[] = [
            'type' => 'cards',
            'data' => [
                'cards' => [
                    ['title' => 'Registration', 'body' => 'Sign up in minutes with flexible registration types.', 'icon' => 'ticket'],
                    ['title' => 'Venue', 'body' => 'Everything you need to know before you arrive.', 'icon' => 'map-pin'],
                    ['title' => 'Community', 'body' => 'Join a growing community across every event.', 'icon' => 'users'],
                ],
            ],
        ];

        $blocks[] = [
            'type' => 'text',
            'data' => ['heading' => 'About '.$brand->name, 'body' => fake()->paragraphs(2, true)],
        ];

        return $blocks;
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedEvent(Brand $brand, int $index, Collection $members, int $registrations): Event
    {
        $slug = Str::slug($brand->name).'-'.(now()->year + ($index - 1));

        $existing = Event::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            // Already seeded on a previous run — registrations, quotas and
            // admissions hang off the event, so skip the whole block rather
            // than double-consume quotas. Still returned, so the 'home' page
            // can reference it as the brand's next event.
            return $existing;
        }

        $event = Event::factory()->published()->create([
            'name' => "{$brand->name} ".(now()->year + ($index - 1)),
            'slug' => $slug,
        ]);

        $quota = Quota::factory()->withCapacity(max($registrations * 2, 50))->create([
            'name' => 'Visitor places',
        ]);

        $visitor = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->create(['name' => 'Visitor']);
        $visitor->quotas()->attach($quota);

        $crew = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->requiringApproval()
            ->create(['name' => 'Crew']);

        if ($members->isEmpty() || $registrations === 0) {
            return $event;
        }

        $submit = app(SubmitRegistration::class);
        app(ConfirmRegistration::class);

        // Through the real Actions: quotas consume, events fire, Admission
        // issues — the demo data exercises the production path.
        $members->random(min($registrations, $members->count()))
            ->each(function (User $user) use ($submit, $visitor): void {
                $submit->handle($visitor, $user->getKey(), new SubmitRegistrationData(
                    organizationName: null,
                    billingRecipientName: null,
                    billingEmail: null,
                ));
            });

        // A few pending crew applications for the approval queue.
        $members->random(min(3, $members->count()))
            ->each(function (User $user) use ($submit, $crew): void {
                $submit->handle($crew, $user->getKey(), new SubmitRegistrationData(
                    organizationName: null,
                    billingRecipientName: null,
                    billingEmail: null,
                ));
            });

        return $event;
    }
}
