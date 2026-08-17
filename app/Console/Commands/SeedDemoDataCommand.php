<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Models\Order;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Actions\ConfirmRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationSettlement;
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
 * demo data exercises the same paths as production, admissions, the media
 * library and the commerce settlement chain (orders, invoices, manual
 * payments) included.
 */
class SeedDemoDataCommand extends Command
{
    /**
     * Background colours for generated placeholder media, picked per file so
     * a brand's library is visually distinguishable in the console grid.
     *
     * @var list<array{int, int, int}>
     */
    private const array MEDIA_PALETTE = [
        [37, 99, 235],
        [217, 70, 239],
        [16, 185, 129],
        [234, 88, 12],
        [99, 102, 241],
        [14, 165, 233],
    ];

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

        $this->linkPublicStorage();

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
    private function seedBrands(int $count): Collection
    {
        $names = ['SXLan', 'Xpertise', 'Nordcon', 'Playa', 'Brickfest', 'Synthwave', 'Quizzity', 'Meeple'];

        $operator = Operator::query()->firstWhere('name', 'Konventix Dev Operator');

        return collect(range(1, $count))->map(function (int $index) use ($names, $operator): Brand {
            $name = $names[$index - 1] ?? 'Brand '.$index;
            $slug = Str::slug($name);

            $brand = Brand::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'theme_tokens' => []],
            );

            // Settling money needs a legal seller identity: OperatorSettings
            // refuses invoice issuance for a brand without an operator.
            if ($brand->operator_id === null && $operator !== null) {
                $brand->operator()->associate($operator)->save();
            }

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

        // Before the events: badge templates and the home page reference the
        // brand's media by ULID, so the library has to exist first.
        $media = $this->seedMedia($brand);

        $seededEvents = collect(range(1, max(1, $events)))
            ->map(fn (int $index): Event => $this->seedEvent($brand, $index, $members, $registrationsPerEvent, $media));

        // Settlement dispatches queued jobs; on a sync queue driver their
        // completion forgets the ambient brand context
        // (PropagatesBrandContextToQueue), so re-assert it before seeding
        // anything else brand-owned.
        app(BrandContext::class)->set($brand);

        // The 'home' page's countdown/cards blocks want the brand's next
        // event, not just "an" event — falls back to whatever exists if
        // sorting by wall-clock start yields nothing (e.g. --default with
        // 0 events requested still seeds 1 via max(1, $events) above).
        $firstEvent = $seededEvents->sortBy(fn (Event $event): string => $event->starts_at->toDateTimeString())->first()
            ?? Event::query()->orderBy('starts_at')->first();

        $this->seedStaticPages($brand, $firstEvent, $media);
    }

    /**
     * The brand's slice of the media library (ADR-0035): placeholder images
     * pushed through the real StoreMediaUpload action, so the demo carries
     * stored files, generated `thumb` variants and rows the operator console
     * can list, search and refuse to delete.
     *
     * Keyed by purpose — the hero lands in the home page's hero block and the
     * logo/background in every event's badge template, which is what gives
     * the library non-zero usage references to show; the sponsor logos stay
     * unreferenced so the deletable case exists too.
     *
     * Idempotent per file rather than per brand: an item is looked up by its
     * filename and only uploaded when missing, so re-runs — and databases
     * seeded before this slice existed — top up instead of duplicating.
     *
     * @return array<string, MediaItem>
     */
    private function seedMedia(Brand $brand): array
    {
        $slug = Str::slug($brand->name);

        /** @var array<string, array{string, int, int, string, string}> $definitions */
        $definitions = [
            'logo' => ["{$slug}-logo.png", 640, 640, $brand->name, "{$brand->name} logo"],
            'hero' => ["{$slug}-hero.jpg", 1600, 900, $brand->name, "The {$brand->name} main hall"],
            'badge-background' => ["{$slug}-badge-background.png", 880, 1240, $brand->name, "{$brand->name} badge background"],
            'sponsor-northwind' => ['sponsor-northwind.png', 720, 360, 'Northwind', 'Northwind Systems logo'],
            'sponsor-hexatech' => ['sponsor-hexatech.png', 720, 360, 'Hexatech', 'Hexatech logo'],
        ];

        // Uploads are attributed to the operator admin the console logs in
        // as; the column is nullable, so a database without it is fine.
        $uploader = User::query()->firstWhere('email', 'admin@konventix.test');

        $store = app(StoreMediaUpload::class);

        $media = [];

        foreach ($definitions as $key => [$filename, $width, $height, $label, $altText]) {
            // Brand context is set: the scope constrains the lookup (ADR-0010).
            $existing = MediaItem::query()->firstWhere('original_filename', $filename);

            if ($existing !== null) {
                $media[$key] = $existing;

                continue;
            }

            $upload = $this->placeholderUpload($filename, $width, $height, $label);

            // Explicit brand ULID, not ambient context: uploads normally
            // arrive on the brand-less operator host (ADR-0029).
            $media[$key] = $store->handle(
                brandId: $brand->getKey(),
                file: $upload,
                altText: $altText,
                uploadedByUserId: $uploader?->getKey(),
            );

            @unlink($upload->getPathname());
        }

        return $media;
    }

    /**
     * A generated placeholder image on disk, wrapped as an `UploadedFile` so
     * it travels the production upload path untouched — which is also what
     * produces the `thumb` variant. Drawn with bare GD, the same dependency
     * ThumbnailGenerator already assumes, so no demo-only package appears.
     */
    private function placeholderUpload(string $filename, int $width, int $height, string $label): UploadedFile
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        [$red, $green, $blue] = self::MEDIA_PALETTE[abs(crc32($filename)) % count(self::MEDIA_PALETTE)];

        imagefilledrectangle($image, 0, 0, $width, $height, $this->allocate($image, $red, $green, $blue));

        // A darker footer band: flat colour swatches are hard to tell apart
        // once the console scales them down to thumbnails.
        imagefilledrectangle(
            $image,
            0,
            (int) ($height * 0.78),
            $width,
            $height,
            $this->allocate($image, (int) ($red * 0.6), (int) ($green * 0.6), (int) ($blue * 0.6)),
        );

        $this->stampLabel($image, $label, $width, (int) ($height * 0.78), $red, $green, $blue);

        $path = (string) tempnam(sys_get_temp_dir(), 'konventix-media-');

        if (str_ends_with($filename, '.jpg')) {
            imagejpeg($image, $path, 82);
        } else {
            imagepng($image, $path);
        }

        // No imagedestroy(): deprecated as a no-op since PHP 8.0, and the
        // GdImage goes out of scope here anyway.

        // `test: true` skips the is_uploaded_file() check — the file comes
        // from this process, not from a POST.
        return new UploadedFile($path, $filename, test: true);
    }

    /**
     * Centres a label in the image's upper region. GD's built-in fonts top
     * out at a few pixels, so the text is drawn once at font size on a canvas
     * matching the background and then scaled up — big readable type with no
     * font file to ship and no visible seam against the background.
     */
    private function stampLabel(GdImage $image, string $label, int $width, int $height, int $red, int $green, int $blue): void
    {
        $label = strtoupper(mb_substr($label, 0, 20));

        $textWidth = max(1, imagefontwidth(5) * mb_strlen($label));
        $textHeight = max(1, imagefontheight(5));

        $canvas = imagecreatetruecolor($textWidth, $textHeight);
        imagefilledrectangle($canvas, 0, 0, $textWidth, $textHeight, $this->allocate($canvas, $red, $green, $blue));
        imagestring($canvas, 5, 0, 0, $label, $this->allocate($canvas, 255, 255, 255));

        $scale = max(2, (int) floor(($width * 0.7) / $textWidth));
        $scaledWidth = $textWidth * $scale;
        $scaledHeight = $textHeight * $scale;

        imagecopyresampled(
            $image,
            $canvas,
            (int) (($width - $scaledWidth) / 2),
            (int) (($height - $scaledHeight) / 2),
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $textWidth,
            $textHeight,
        );
    }

    /**
     * A palette entry as a GD colour index. Channels are clamped rather than
     * trusted (the footer band scales them down), and an exhausted palette —
     * `imagecolorallocate()` returning false — falls back to index 0 so a
     * placeholder image is never worth failing the whole seed over.
     */
    private function allocate(GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate(
            $image,
            max(0, min(255, $red)),
            max(0, min(255, $green)),
            max(0, min(255, $blue)),
        );

        return $color === false ? 0 : $color;
    }

    /**
     * The event's fallback badge template (D-ADM-05), pointing at the brand's
     * media by ULID and never a path (ADR-0035). This is what puts genuine
     * usage references in the library — a seeded badge PDF renders with the
     * artwork, and the console refuses to delete a file the badge needs.
     *
     * Idempotent on the event's fallback template, like commerce is on the
     * Supporter type: a database seeded before this slice gets topped up.
     *
     * @param  array<string, MediaItem>  $media
     */
    private function seedBadgeTemplate(Event $event, array $media): void
    {
        $alreadySeeded = BadgeTemplate::query()
            ->where('event_id', $event->getKey())
            ->whereNull('role')
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $template = BadgeTemplate::factory()
            ->forEvent($event->getKey())
            ->withLayout(new BadgeLayoutData(
                logoMediaId: ($media['logo'] ?? null)?->getKey(),
                backgroundMediaId: ($media['badge-background'] ?? null)?->getKey(),
                footerText: $event->name,
            ))
            ->create(['name' => "{$event->name} badge"]);

        $library = app(MediaLibrary::class);

        foreach ($template->layout->mediaIds() as $mediaId) {
            $library->recordUsage($mediaId, 'admission', 'badge_template', $template->getKey());
        }
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

    /**
     * @param  array<string, MediaItem>  $media
     */
    private function seedStaticPages(Brand $brand, ?Event $firstEvent, array $media): void
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
        $home = StaticPage::query()->firstOrCreate(
            ['slug' => 'home'],
            [
                'title' => $brand->name,
                'published_at' => now(),
                'blocks' => $this->homeBlocks($brand, $firstEvent, $media),
            ],
        );

        $hero = $media['hero'] ?? null;

        if ($hero !== null) {
            // The hero block stores a resolved URL rather than a ULID today,
            // but the page does reference the file — recording the usage is
            // what stops the console deleting it out from under the page.
            // Idempotent, so a re-run does not stack references.
            app(MediaLibrary::class)->recordUsage($hero->getKey(), 'platform', 'static_page', $home->getKey());
        }
    }

    /**
     * @param  array<string, MediaItem>  $media
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function homeBlocks(Brand $brand, ?Event $firstEvent, array $media): array
    {
        $hero = $media['hero'] ?? null;

        $blocks = [
            [
                'type' => 'hero',
                'data' => [
                    'heading' => $brand->name,
                    'subheading' => 'Event-agnostic event management, done right.',
                    'ctaLabel' => $firstEvent !== null ? 'View the event' : null,
                    'ctaUrl' => $firstEvent !== null ? '/events/'.$firstEvent->slug : null,
                    // Resolved through the library — the only sanctioned way
                    // to turn a media ULID into something renderable
                    // (ADR-0035).
                    'imageUrl' => $hero !== null
                        ? app(MediaLibrary::class)->urlFor($hero->getKey())
                        : null,
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
     * @param  array<string, MediaItem>  $media
     */
    private function seedEvent(Brand $brand, int $index, Collection $members, int $registrations, array $media): Event
    {
        $slug = Str::slug($brand->name).'-'.(now()->year + ($index - 1));

        $existing = Event::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            // Already seeded on a previous run — registrations, quotas and
            // admissions hang off the event, so skip the whole block rather
            // than double-consume quotas. Still returned, so the 'home' page
            // can reference it as the brand's next event. Commerce seeding is
            // separately idempotent, so a database seeded before it existed
            // still gets orders and invoices topped up. The badge template is
            // separately idempotent for the same reason.
            $this->seedCommerce($brand, $existing, $members, $registrations);
            $this->seedBadgeTemplate($existing, $media);

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

        // Before the early return below: an event with no registrations still
        // wants a badge design in the console.
        $this->seedBadgeTemplate($event, $media);

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

        $this->seedCommerce($brand, $event, $members, $registrations);

        return $event;
    }

    /**
     * The money-moving slice of the demo: a priced, invoice-settled
     * "Supporter" type whose registrations flow through the real settlement
     * chain — SubmitRegistration confirms, RequestSettlement emits, Commerce
     * places the order and issues the invoice (ADR-0006) — then every second
     * invoice gets a manual bank-transfer payment booked, leaving the rest
     * open for the dunning/receivables views.
     *
     * Idempotent on the Supporter type's existence, mirroring how events are
     * skipped wholesale: re-running must not double-submit registrations.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedCommerce(Brand $brand, Event $event, Collection $members, int $registrations): void
    {
        $alreadySeeded = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->where('name', 'Supporter')
            ->exists();

        if ($alreadySeeded) {
            return;
        }

        $supporter = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->settlement(RegistrationSettlement::Invoice)
            ->priced(unitAmountNet: '49.00')
            ->create(['name' => 'Supporter', 'terms_days' => 14]);

        if ($members->isEmpty() || $registrations === 0) {
            return;
        }

        $submit = app(SubmitRegistration::class);
        $bookPayment = app(BookManualPayment::class);

        // Roughly a third of the visitor volume upgrades to a paid ticket.
        $supporterCount = min(max(1, intdiv($registrations, 3)), $members->count());

        $members->random($supporterCount)->values()
            ->each(function (User $user, int $index) use ($submit, $bookPayment, $supporter, $brand): void {
                // Each settled registration dispatches the invoice-document
                // job; on a sync queue its completion forgets the ambient
                // brand context, so re-assert it every iteration.
                app(BrandContext::class)->set($brand);

                // Invoice settlement requires the full billing party at
                // submission (a legally valid invoice needs an address).
                $registration = $submit->handle($supporter, $user->getKey(), new SubmitRegistrationData(
                    organizationName: fake()->boolean(30) ? fake()->company() : null,
                    billingRecipientName: $user->name,
                    billingEmail: $user->email,
                    billingAddressLine: fake()->streetAddress(),
                    billingPostalCode: fake()->postcode(),
                    billingCity: fake()->city(),
                    billingCountryCode: 'DE',
                ));

                // Every second invoice is settled by bank transfer through
                // the real adapter chain; the rest stay open receivables.
                if ($index % 2 === 0) {
                    $order = Order::query()
                        ->where('settles_id', $registration->id)
                        ->first();

                    if ($order !== null) {
                        $bookPayment->handle($order);
                    }
                }
            });
    }
}
