# Timetable Implementation Plan (0.9.0, sub-project C)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An organizer plans and publishes an event's programme; a participant sees it on a public page, on a phone, and as "Jetzt / Gleich" in the live hub.

**Architecture:** One module, Agenda, owning four tables — tracks, locations, items and (half two) the competition selection. No cross-module contract except the events Agenda emits and, later, Competition's lifecycle events it projects. The grid is a plain HTML table: rows are the distinct times of a day, columns are tracks, an item spans its rows with `rowspan`.

**Tech Stack:** Laravel 12 modules (`app-modules/agenda`), Pest on Postgres via Sail, spatie/laravel-data + typescript-transformer, Inertia v3 + Vue 3 + shadcn-vue, Wayfinder, Laravel Pennant.

**Spec:** `docs/superpowers/specs/2026-09-23-timetable-design.md` (D-AGD-01..09).

## Global Constraints

- **No local test runs** (operator instruction, 2026-09-23). Write the tests; do not execute them. The GitLab pipeline on `develop` is the test runner — pipelines supersede, only the last one matters. **Formatting and linting DO run locally**: the `format-on-edit.sh` hook handles pint/prettier/eslint on every write, and you may run `DOCKER_CONTEXT=default vendor/bin/sail npm run lint`, `… npm run types:check`, `… npm run build` and `… vendor/bin/pint --dirty` yourself. Never run `composer verify`, `composer test`, or `artisan test`.
- All work commits to `develop` directly. Do not push — the controller pushes.
- `DOCKER_CONTEXT=default` prefixes every Sail command (the host context is `desktop-linux`, the stack lives in `default`).
- `brand_id` is never mass-assigned: `BelongsToBrand` stamps it from context; projections and admin-host writes use `->forceFill(['brand_id' => …])` and `withoutBrandScope()->where('brand_id', …)`.
- Cross-module `use` statements only for `Konventix\<Module>\Events\*` and Platform's host services (`HubRegistry`, `HubCard`, `ActiveEventData`, `NavigationRegistry`). Never another module's model, action or Data.
- Times are wall-clock in the event's timezone (ADR-0027); store `timestamptz`, anchor `->utc()` before assigning to a datetime cast.
- Participant-facing copy is **German**; operator-console copy is **English**.
- ULID primary keys (ADR-0014); no foreign keys across module boundaries; `Data` objects with `rules()` per endpoint (ADR-0009); an Action per write (ADR-0015); listeners registered explicitly in the provider.
- Commit messages: one plain sentence in the repo's style, ending with:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01Pxs1vGJjmPbCePd3MDbLU6`
- Read before touching the matching paths: `.ai/rules/models.md`, `.ai/rules/src.md`, `.ai/rules/data.md`, `.ai/rules/js.md`, `.ai/rules/pages.md`, `.ai/rules/providers.md`, `.ai/rules/tests.md`.
- `app-modules/agenda/SPEC.md` carries a "post-release module — do not start" banner and five open questions. The design supersedes both; Task 5 rewrites the SPEC.

---

## File map

**Agenda module** (`app-modules/agenda/`)
- `database/migrations/2026_09_24_100000_create_agenda_tracks_table.php`
- `database/migrations/2026_09_24_100100_create_agenda_locations_table.php`
- `database/migrations/2026_09_24_100200_create_agenda_items_table.php`
- `src/Models/{Track,Location,AgendaItem}.php` + `database/factories/{TrackFactory,LocationFactory,AgendaItemFactory}.php`
- `src/Enums/{ItemState,Permission}.php`
- `src/Data/{TrackData,LocationData,AgendaItemData,ProgrammeDayData,ProgrammeRowData,ProgrammeCellData,NowNextCardData}.php`
- `src/Actions/{SaveAgendaItem,PublishAgendaItem,CancelAgendaItem,SaveTracks,SaveLocations}.php`
- `src/Events/{AgendaItemPublished,AgendaItemRescheduled,AgendaItemCancelled}.php`
- `src/Programme/{ProgrammeBuilder,NowNext}.php`
- `src/Http/Controllers/{ProgrammeController}.php`, `src/Http/Controllers/Admin/{AgendaAdminController}.php`
- `routes/web/agenda.php`, `routes/admin/agenda.php`
- `resources/js/pages/agenda/Programme.vue`, `resources/js/pages/admin/agenda/Edit.vue`
- `resources/js/components/{ProgrammeGrid.vue,ProgrammeList.vue}`, `resources/js/components/hub/NowNextCard.vue`
- `src/Metrics/AgendaMetrics.php`, `src/Providers/AgendaServiceProvider.php`
- `SPEC.md`, `TODO.md`

**Outside Agenda**
- `contrib/observability/src/platform.mjs` (one panel), `docs/operations.md` (one paragraph)
- `app-modules/event/resources/js/pages/admin/events/Dashboard.vue` (a "Programme" link)

---

### Task 1: The model — tracks, locations, items

**Files:**
- Create: the three migrations, `src/Models/Track.php`, `Location.php`, `AgendaItem.php`, the three factories, `src/Enums/ItemState.php`
- Test: `app-modules/agenda/tests/AgendaModelTest.php`

**Interfaces:**
- Produces: `Konventix\Agenda\Models\{Track,Location,AgendaItem}`; `Konventix\Agenda\Enums\ItemState::{Draft,Published,Cancelled}` (backed `draft|published|cancelled`); `AgendaItem` casts `starts_at`/`ends_at` to `immutable_datetime`, `state` to `ItemState`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;

it('keeps track and location names unique per event', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();

    Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne']);
    Location::factory()->create(['event_id' => $eventId, 'name' => 'Halle 3']);

    expect(fn () => Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses an item with neither a start nor an end', function (): void {
    $this->withBrand();

    expect(fn () => AgendaItem::factory()->create(['starts_at' => null, 'ends_at' => null]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('allows an item with only a start, and one with only an end', function (): void {
    $this->withBrand();

    $startOnly = AgendaItem::factory()->create(['starts_at' => now(), 'ends_at' => null]);
    $endOnly = AgendaItem::factory()->create(['starts_at' => null, 'ends_at' => now()->addHour()]);

    expect($startOnly->fresh()->ends_at)->toBeNull()
        ->and($endOnly->fresh()->starts_at)->toBeNull()
        ->and($startOnly->fresh()->state)->toBe(ItemState::Draft);
});

it('leaves items placeless when their track or location goes away', function (): void {
    $this->withBrand();
    $track = Track::factory()->create();
    $location = Location::factory()->create();
    $item = AgendaItem::factory()->create(['track_id' => $track->id, 'location_id' => $location->id]);

    $track->delete();
    $location->delete();

    expect($item->fresh()->track_id)->toBeNull()
        ->and($item->fresh()->location_id)->toBeNull();
});
```

- [ ] **Step 2: Write the migrations**

`agenda_tracks`:

```php
Schema::create('agenda_tracks', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
    // Event is another module — plain ULID, no FK (ADR-0014).
    $table->ulid('event_id');
    $table->string('name');
    $table->unsignedSmallInteger('position')->default(0);
    $table->timestamps();

    $table->unique(['event_id', 'name']);
    $table->index(['brand_id', 'event_id']);
});
```

`agenda_locations` — same shape plus `$table->ulid('venue_id')->nullable();` after `event_id`, and the same `unique(['event_id', 'name'])`. Docblock: Agenda owns its places because the platform has no room entity — Resources models bookable seats and Event's `Venue` is a building (design §4.3).

`agenda_items`:

```php
Schema::create('agenda_items', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
    $table->ulid('event_id');
    // Same module — real FKs, and a vanished track leaves the item placeless
    // rather than deleting a programme entry somebody published.
    $table->foreignUlid('track_id')->nullable()->constrained('agenda_tracks')->nullOnDelete();
    $table->foreignUlid('location_id')->nullable()->constrained('agenda_locations')->nullOnDelete();
    $table->string('title');
    $table->text('description')->nullable();
    $table->timestampTz('starts_at')->nullable();
    $table->timestampTz('ends_at')->nullable();
    $table->string('location_note')->nullable();
    $table->string('speaker')->nullable();
    $table->string('state', 16)->default('draft');
    $table->string('cancelled_reason')->nullable();
    $table->timestamps();

    $table->index(['event_id', 'starts_at']);
});

// D-AGD-04: at least one of the two, enforced in the database so no writer
// can produce an item the grid cannot place.
DB::statement('ALTER TABLE agenda_items ADD CONSTRAINT agenda_items_has_a_time
    CHECK (starts_at IS NOT NULL OR ends_at IS NOT NULL)');
```

`down()` drops the three tables in reverse order.

- [ ] **Step 3: Write the models, the enum and the factories**

`ItemState`: backed enum `Draft = 'draft'`, `Published = 'published'`, `Cancelled = 'cancelled'`, with a docblock naming D-AGD-08 (cancelled stays visible and struck).

Each model: `BelongsToBrand`, `HasUlids`, `HasFactory` wired the way `app-modules/admission/src/Models/Admission.php` wires it (check whether it declares `newFactory()` or relies on the namespace convention, and match), `@property` docblocks, `$fillable` without `brand_id`. `AgendaItem::casts()` returns `['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'state' => ItemState::class]`. `AgendaItem` gets `track()` and `location()` `belongsTo` relations (same module, allowed) and a `scopeVisible(Builder $query)` that filters `whereIn('state', [ItemState::Published, ItemState::Cancelled])` — what the public page shows.

Factories set no `brand_id`; `AgendaItemFactory::definition()` gives `event_id` a ULID, `title` a `fake()->sentence(3)`, `starts_at` `now()`, `ends_at` `now()->addHour()`, `state` `ItemState::Draft`.

- [ ] **Step 4: Format and commit** (no test run — Global Constraints)

```bash
DOCKER_CONTEXT=default vendor/bin/sail vendor/bin/pint --dirty --format agent
git add app-modules/agenda && git commit -m "A programme has tracks, places and items"
```

---

### Task 2: Writing the programme — Actions, Data, events

**Files:**
- Create: `src/Data/{TrackData,LocationData,AgendaItemData}.php`, `src/Actions/{SaveAgendaItem,PublishAgendaItem,CancelAgendaItem,SaveTracks,SaveLocations}.php`, `src/Events/{AgendaItemPublished,AgendaItemRescheduled,AgendaItemCancelled}.php`, `src/Enums/Permission.php`
- Test: `app-modules/agenda/tests/AgendaWriteTest.php`

**Interfaces:**
- Consumes: Task 1's models and `ItemState`.
- Produces:

```php
final class AgendaItemData extends Data {            // #[TypeScript]
    public function __construct(
        public ?string $id, public string $title, public ?string $description,
        public ?string $trackId, public ?string $locationId, public ?string $locationNote,
        public ?string $speaker, public ?string $startsAt, public ?string $endsAt,
    ) {}
    public static function rules(): array;           // title required|max:200; startsAt/endsAt nullable|date; trackId/locationId nullable|ulid
}
SaveAgendaItem::handle(string $eventId, string $brandId, AgendaItemData $data, ?AgendaItem $item = null): AgendaItem
PublishAgendaItem::handle(AgendaItem $item): AgendaItem      // emits Published, or Rescheduled if already published and times changed
CancelAgendaItem::handle(AgendaItem $item, ?string $reason): AgendaItem
SaveTracks::handle(string $eventId, string $brandId, array $tracks): void     // list<TrackData>, replaces the set, keeps ids
SaveLocations::handle(string $eventId, string $brandId, array $locations): void
```

Events (`final readonly class`, ULIDs and scalars only):

```php
AgendaItemPublished(string $agendaItemId, string $eventId, string $brandId, string $title, ?string $startsAt, ?string $endsAt)
AgendaItemRescheduled(string $agendaItemId, string $eventId, string $brandId, ?string $startsAt, ?string $endsAt)
AgendaItemCancelled(string $agendaItemId, string $eventId, string $brandId, ?string $reason)
```

`Permission::ManageAgenda = 'agenda.manage-agenda'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Agenda\Actions\CancelAgendaItem;
use Konventix\Agenda\Actions\PublishAgendaItem;
use Konventix\Agenda\Actions\SaveAgendaItem;
use Konventix\Agenda\Actions\SaveTracks;
use Konventix\Agenda\Data\AgendaItemData;
use Konventix\Agenda\Data\TrackData;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Events\AgendaItemCancelled;
use Konventix\Agenda\Events\AgendaItemPublished;
use Konventix\Agenda\Events\AgendaItemRescheduled;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Track;

function agendaItemData(array $overrides = []): AgendaItemData
{
    return AgendaItemData::from(array_merge([
        'id' => null, 'title' => 'Lötkurs', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => '2026-11-20T15:00:00+01:00', 'endsAt' => '2026-11-20T16:30:00+01:00',
    ], $overrides));
}

it('refuses an item with neither time before it reaches the database', function (): void {
    $this->withBrand();

    expect(fn () => AgendaItemData::validateAndCreate([
        'id' => null, 'title' => 'Kein Zeitpunkt', 'description' => null,
        'trackId' => null, 'locationId' => null, 'locationNote' => null, 'speaker' => null,
        'startsAt' => null, 'endsAt' => null,
    ]))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('publishes once, then reschedules', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $item = app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData());
    EventBus::fake([AgendaItemPublished::class, AgendaItemRescheduled::class]);

    app(PublishAgendaItem::class)->handle($item);
    EventBus::assertDispatchedTimes(AgendaItemPublished::class, 1);
    expect($item->fresh()->state)->toBe(ItemState::Published);

    app(SaveAgendaItem::class)->handle($eventId, $brand->id, agendaItemData([
        'startsAt' => '2026-11-20T17:00:00+01:00',
    ]), $item->fresh());

    EventBus::assertDispatchedTimes(AgendaItemRescheduled::class, 1);
    EventBus::assertDispatchedTimes(AgendaItemPublished::class, 1);
});

it('cancels with a reason and keeps the row', function (): void {
    $brand = $this->withBrand();
    $item = app(SaveAgendaItem::class)->handle((string) Str::ulid(), $brand->id, agendaItemData());
    app(PublishAgendaItem::class)->handle($item);
    EventBus::fake([AgendaItemCancelled::class]);

    app(CancelAgendaItem::class)->handle($item->fresh(), 'Referent krank');

    expect($item->fresh()->state)->toBe(ItemState::Cancelled)
        ->and($item->fresh()->cancelled_reason)->toBe('Referent krank');
    EventBus::assertDispatched(AgendaItemCancelled::class, fn (AgendaItemCancelled $e): bool => $e->reason === 'Referent krank');
});

it('replaces the track set while keeping the ids of tracks that stay', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    app(SaveTracks::class)->handle($eventId, $brand->id, [
        TrackData::from(['id' => null, 'name' => 'Bühne', 'position' => 1]),
        TrackData::from(['id' => null, 'name' => 'Turniere', 'position' => 2]),
    ]);
    $stage = Track::query()->where('name', 'Bühne')->firstOrFail();
    $item = AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id]);

    app(SaveTracks::class)->handle($eventId, $brand->id, [
        TrackData::from(['id' => $stage->id, 'name' => 'Hauptbühne', 'position' => 1]),
    ]);

    expect(Track::query()->where('event_id', $eventId)->count())->toBe(1)
        ->and($stage->fresh()->name)->toBe('Hauptbühne')
        ->and($item->fresh()->track_id)->toBe($stage->id);
});
```

`agendaItemData()` is used by Task 3's test too — put it in `app-modules/agenda/tests/Helpers.php` (`tests/Pest.php` globs every module's `Helpers.php`), not in the test file. Pest helper names are process-global; the `agenda` prefix keeps it unique.

- [ ] **Step 2: Write the Data objects**

`TrackData(?string $id, string $name, int $position)` and `LocationData(?string $id, string $name, ?string $venueId, int $position)`, both `#[TypeScript]`, both with `rules()` (`name` required, max 80; `position` integer, min 0; `venueId` nullable ulid).

`AgendaItemData` as in **Interfaces**, `#[TypeScript]`, with:

```php
/** @return array<string, array<int, mixed>> */
public static function rules(): array
{
    return [
        'title' => ['required', 'string', 'max:200'],
        'description' => ['nullable', 'string', 'max:5000'],
        'trackId' => ['nullable', 'ulid'],
        'locationId' => ['nullable', 'ulid'],
        'locationNote' => ['nullable', 'string', 'max:120'],
        'speaker' => ['nullable', 'string', 'max:120'],
        // D-AGD-04: at least one of the two. `required_without` on both sides
        // is what makes "neither" the only refused combination.
        'startsAt' => ['nullable', 'date', 'required_without:endsAt'],
        'endsAt' => ['nullable', 'date', 'required_without:startsAt', 'after:startsAt'],
    ];
}
```

`after:startsAt` is skipped by Laravel when `startsAt` is absent, which is the end-only shape — verify that in the test rather than assuming.

- [ ] **Step 3: Write the Actions and the events**

`SaveAgendaItem::handle()` creates or updates, anchoring both instants with `CarbonImmutable::parse($value)->utc()` before assigning (Eloquent's datetime cast writes wall-clock digits without zone conversion — sub-project A found this the hard way), writes `brand_id` via `forceFill` on create, and — when the item is already `Published` and either instant changed — emits `AgendaItemRescheduled` after the save.

`PublishAgendaItem::handle()` sets `state = Published` and emits `AgendaItemPublished`; a second call on an already-published item is a no-op and emits nothing.

`CancelAgendaItem::handle()` sets `state = Cancelled` plus the reason and emits `AgendaItemCancelled`; the row stays.

`SaveTracks`/`SaveLocations` take the complete desired set: rows whose `id` is present are updated, rows without an `id` are created, rows of that event absent from the payload are deleted (leaving their items placeless via `nullOnDelete`). Wrap in `DB::transaction`, and use `withoutBrandScope()->where('brand_id', $brandId)->where('event_id', $eventId)` for every read — these run from the admin host, where `BrandScope` hardens to zero rows (ADR-0029).

- [ ] **Step 4: Format, generate types, commit**

```bash
DOCKER_CONTEXT=default vendor/bin/sail composer types:generate   # restore the known TicketManagerData `undefined<…>` line to HEAD if it regresses
DOCKER_CONTEXT=default vendor/bin/sail vendor/bin/pint --dirty --format agent
git add app-modules/agenda resources/js/types && git commit -m "A programme item can be written, published and called off"
```

---

### Task 3: Building the grid — `ProgrammeBuilder` and `NowNext`

**Files:**
- Create: `src/Programme/ProgrammeBuilder.php`, `src/Programme/NowNext.php`, `src/Data/{ProgrammeDayData,ProgrammeRowData,ProgrammeCellData,NowNextCardData}.php`
- Test: `app-modules/agenda/tests/ProgrammeBuilderTest.php`

**Interfaces:**
- Consumes: Task 1's models, Task 2's `agendaItemData()` helper.
- Produces:

```php
#[TypeScript] final class ProgrammeCellData extends Data {
    public function __construct(
        public string $itemId, public string $title, public ?string $speaker,
        public ?string $location,          // resolved: the location's name, else locationNote, else null
        public ?string $startsAt, public ?string $endsAt,
        public int $rowSpan, public bool $cancelled, public ?string $cancelledReason,
    ) {}
}
#[TypeScript] final class ProgrammeRowData extends Data {
    /** @param array<int, array<int, ProgrammeCellData>> $cells one list per track, in track order */
    public function __construct(public string $time, #[DataCollectionOf(...)] public array $cells) {}
}
#[TypeScript] final class ProgrammeDayData extends Data {
    /** @param array<int, string> $trackNames @param array<int, ProgrammeRowData> $rows @param array<int, ProgrammeCellData> $untracked */
    public function __construct(public string $dayId, public string $label, public string $date,
        public array $trackNames, public array $rows, public array $untracked) {}
}
final class ProgrammeBuilder {
    /** @return array<int, ProgrammeDayData> one per event day that has items */
    public function forEvent(string $eventId, string $brandId, array $days, string $timezone): array;
}
#[TypeScript] final class NowNextCardData extends Data {
    public function __construct(public ?ProgrammeCellData $now, public ?ProgrammeCellData $next, public string $programmeUrl) {}
}
final class NowNext {
    public function forEvent(string $eventId, string $brandId, CarbonImmutable $at): array; // ['now' => ?item, 'next' => ?item]
}
```

`$days` is `list<array{day_id: string, date: string, label: string|null}>` — the caller passes what it has; Agenda does not read Event's tables.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;
use Konventix\Agenda\Programme\NowNext;
use Konventix\Agenda\Programme\ProgrammeBuilder;

function agendaDay(string $date, string $label = 'Freitag'): array
{
    return ['day_id' => (string) Str::ulid(), 'date' => $date, 'label' => $label];
}

it('makes a row per distinct time and spans an item across the rows it covers', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);
    $shop = Track::factory()->create(['event_id' => $eventId, 'name' => 'Workshops', 'position' => 2]);

    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T14:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $shop->id, 'state' => ItemState::Published,
        'title' => 'Lötkurs', 'starts_at' => '2026-11-20T13:15:00Z', 'ends_at' => null]);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin');

    // 14:00, 14:15, 15:00 local — three distinct times.
    expect($days)->toHaveCount(1)
        ->and($days[0]->trackNames)->toBe(['Bühne', 'Workshops'])
        ->and($days[0]->rows)->toHaveCount(3)
        ->and($days[0]->rows[0]->time)->toBe('14:00')
        ->and($days[0]->rows[0]->cells[0][0]->title)->toBe('Eröffnung')
        ->and($days[0]->rows[0]->cells[0][0]->rowSpan)->toBe(2)   // half-open: covers 14:00 and 14:15, not the 15:00 boundary
        ->and($days[0]->rows[1]->time)->toBe('14:15')
        ->and($days[0]->rows[1]->cells[1][0]->title)->toBe('Lötkurs')
        ->and($days[0]->rows[1]->cells[1][0]->rowSpan)->toBe(1);  // no end → one row
});

it('hides drafts, keeps cancelled items visible with their reason', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Draft,
        'title' => 'Geheim', 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Cancelled,
        'title' => 'Fällt aus', 'cancelled_reason' => 'Referent krank', 'starts_at' => '2026-11-20T14:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin');

    $titles = collect($days[0]->untracked)->pluck('title')->all();
    expect($titles)->toBe(['Fällt aus'])
        ->and($days[0]->untracked[0]->cancelled)->toBeTrue()
        ->and($days[0]->untracked[0]->cancelledReason)->toBe('Referent krank');
});

it('resolves a location name, falls back to the note, and tolerates neither', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $hall = Location::factory()->create(['event_id' => $eventId, 'name' => 'Halle 3']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'A', 'location_id' => $hall->id, 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'B', 'location_note' => 'Parkplatz', 'starts_at' => '2026-11-20T13:30:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'C', 'starts_at' => '2026-11-20T14:00:00Z']);

    $cells = collect(app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin')[0]->untracked)
        ->keyBy('title');

    expect($cells['A']->location)->toBe('Halle 3')
        ->and($cells['B']->location)->toBe('Parkplatz')
        ->and($cells['C']->location)->toBeNull();
});

it('splits items across the days they fall on', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Freitag', 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Samstag', 'starts_at' => '2026-11-21T13:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id,
        [agendaDay('2026-11-20', 'Freitag'), agendaDay('2026-11-21', 'Samstag')], 'Europe/Berlin');

    expect($days)->toHaveCount(2)
        ->and($days[0]->untracked[0]->title)->toBe('Freitag')
        ->and($days[1]->untracked[0]->title)->toBe('Samstag');
});

it('answers what is running now and what starts next', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Läuft', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T15:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Gleich', 'starts_at' => '2026-11-20T16:00:00Z', 'ends_at' => null]);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Cancelled,
        'title' => 'Fällt aus', 'starts_at' => '2026-11-20T15:30:00Z']);

    $answer = app(NowNext::class)->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T14:00:00Z'));

    // A cancelled item is never "next" — it is not going to happen.
    expect($answer['now']?->title)->toBe('Läuft')
        ->and($answer['next']?->title)->toBe('Gleich');
});
```

`agendaDay()` is file-local; keep the `agenda` prefix.

- [ ] **Step 2: Write `ProgrammeBuilder`**

One query per call: `AgendaItem::query()->visible()->where('event_id', $eventId)->with(['track', 'location'])->orderBy('starts_at')->get()`, then group in PHP.

Per day (`$days` in order):
1. Select the items whose `starts_at` — or, when there is none, `ends_at` — falls on that day's `date` **in the event's timezone** (`->setTimezone($timezone)->toDateString()`).
2. Collect the distinct local `H:i` times: every `starts_at` and every `ends_at` of the day's items, sorted. These are the rows.
3. For each track (by `position`), place each item in the row of its start (or its end, for an end-only item) with **half-open** `rowSpan` = (index of the first row at or after `ends_at`) − (index of the start row), clamped to ≥ 1; 1 when it has no end. Half-open, because rows are time *boundaries*: with Eröffnung 14:00–15:00 followed by Bandcheck 15:00–16:00 in one track, an inclusive span would make both claim the 15:00 row and break the table.
4. Items with `track_id = null` go to `untracked`, in time order.
5. A day with no items is omitted from the result entirely.

Document the rule in the class docblock, citing D-AGD-05/06.

- [ ] **Step 3: Write `NowNext`**

`now` = the visible, non-cancelled item whose `starts_at <= $at` and (`ends_at > $at` or `ends_at is null` and `starts_at` is within the last two hours — an open-ended item is "running" for a bounded while, otherwise every past item would be). `next` = the earliest visible, non-cancelled item with `starts_at > $at`. Both nullable. Say in the docblock why a cancelled item is never `next`.

- [ ] **Step 4: Format, generate types, commit**

```bash
DOCKER_CONTEXT=default vendor/bin/sail composer types:generate
DOCKER_CONTEXT=default vendor/bin/sail vendor/bin/pint --dirty --format agent
git add app-modules/agenda resources/js/types && git commit -m "The programme becomes a grid of the times that actually occur"
```

---

### Task 4: The three surfaces — public page, editor, hub card

**Files:**
- Create: `src/Http/Controllers/ProgrammeController.php`, `src/Http/Controllers/Admin/AgendaAdminController.php`, `routes/web/agenda.php` (replace the empty file), `routes/admin/agenda.php`
- Create: `resources/js/pages/agenda/Programme.vue`, `resources/js/pages/admin/agenda/Edit.vue`, `resources/js/components/ProgrammeGrid.vue`, `resources/js/components/ProgrammeList.vue`, `resources/js/components/hub/NowNextCard.vue`
- Modify: `src/Providers/AgendaServiceProvider.php`, `app-modules/event/resources/js/pages/admin/events/Dashboard.vue`
- Test: `app-modules/agenda/tests/ProgrammePageTest.php`, `app-modules/agenda/tests/AgendaAdminTest.php`

**Interfaces:**
- Consumes: Task 3's `ProgrammeBuilder`, `NowNext`, and their Data; Task 2's Actions, Data and `Permission::ManageAgenda`; Platform's `HubRegistry`, `HubCard`, `ActiveEventData` (sub-project A).
- Produces: routes `agenda.programme` (`/events/{event:slug}/programm`), `admin.events.agenda.edit` / `.update` (`admin/events/{event}/agenda`); hub card key `agenda.now-next`, component `agenda/hub/NowNextCard`, label `Jetzt / Gleich`, order 20.

- [ ] **Step 1: Write the failing tests**

```php
// ProgrammePageTest.php
it('shows the published programme to anyone, without an account', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);           // helper, see below
    agendaPublishedItem($event->id, 'Eröffnung', '2026-11-20T13:00:00Z');

    $this->get("/events/{$event->slug}/programm")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('agenda/Programme')->has('days', 1));
});

it('is absent when the module is off for the brand', function (): void {
    $brand = $this->withBrand();
    $event = agendaPublishedEvent($brand);

    $this->get("/events/{$event->slug}/programm")->assertNotFound();
});
```

```php
// AgendaAdminTest.php
it('refuses the editor without the capability and serves it with', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();      // helper, see below

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->get("/admin/events/{$event->id}/agenda")
        ->assertInertia(fn ($page) => $page->component('admin/agenda/Edit')->has('tracks')->has('locations')->has('items'));
});

it('saves tracks, locations and an item in one submit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->put("/admin/events/{$event->id}/agenda", [
            'tracks' => [['id' => null, 'name' => 'Bühne', 'position' => 1]],
            'locations' => [['id' => null, 'name' => 'Halle 3', 'venueId' => null, 'position' => 1]],
        ])
        ->assertRedirect();

    expect(Konventix\Agenda\Models\Track::query()->where('event_id', $event->id)->count())->toBe(1)
        ->and(Konventix\Agenda\Models\Location::query()->where('event_id', $event->id)->count())->toBe(1);
});
```

Write `agendaPublishedEvent(Brand $brand): Event` and `agendaAdminOperator(): array` into `app-modules/agenda/tests/Helpers.php`. For the operator pair, copy the shape of `app-modules/event/tests/Helpers.php`'s `eventsAdminOperator()` — read it first; it returns `[$operator, $admin]` with the admin host set and the capability granted. Grant `Permission::ManageAgenda->value`.

- [ ] **Step 2: Routes, controllers and the provider**

`routes/web/agenda.php`:

```php
// The programme is an announcement: public, no auth, no capability
// (design §6). The module gate is applied by ModuleServiceProvider.
Route::get('/events/{event:slug}/programm', ProgrammeController::class)->name('agenda.programme');
```

The route binds `{event:slug}`, but Agenda may not import Event's model (ADR-0004). Resolve it the way the controller can: take the slug as a string (`/events/{slug}/programm`, `->where('slug', '[a-z0-9-]+')`) and read the event's id, name, timezone and days from **Agenda's own read model** — which does not exist. Two ways, pick the first: (1) Agenda projects `agenda_event_summaries` (`event_id`, `slug`, `name`, `timezone`, `days json`) from `EventCreated`/`EventDetailsUpdated`/`EventRescheduled`/`EventDaysChanged`, exactly as `app-modules/admission/src/Models/EventSummary.php` does — read that file and its `ProjectEventSummary` listener and mirror them; (2) if that grows past ~80 lines, stop and report it as DONE_WITH_CONCERNS rather than improvising a cross-module query. Register the listeners in `registerListeners()`.

`routes/admin/agenda.php`: `Route::get('/events/{event}/agenda', [AgendaAdminController::class, 'edit'])->name('admin.events.agenda.edit');` and the matching `put(...)->name('admin.events.agenda.update');` — bare routes; the base provider wraps them in the operator host, `auth` and `can:platform.access-admin`.

`AgendaAdminController::edit()` authorises `Permission::ManageAgenda->value`, reads the event's id/name from the summary, and renders `admin/agenda/Edit` with `tracks`, `locations`, `items` (raw rows, not the grid) and `days`. `update()` validates `tracks`/`locations` through their Data and calls `SaveTracks`/`SaveLocations`; item writes go through separate `post`/`put` routes on the same controller using `AgendaItemData`.

Provider: `registerHub()` adds

```php
new HubCard(
    module: 'agenda', key: 'agenda.now-next', label: 'Jetzt / Gleich',
    component: 'agenda/hub/NowNextCard',
    payload: static fn (ActiveEventData $event, Authenticatable $user): Data => app(NowNextCard::class)($event, $user),
    order: 20,
);
```

with `src/Hub/NowNextCard.php` invoking `NowNext` and returning `NowNextCardData`. Also `registerNavigation()` (an `AREA_BRAND` entry "Programm" pointing at the current event's programme is **out of scope** — skip it) and `registerMetrics()` with `agenda_items_published_total{brand}` plus a recorder on `AgendaItemPublished`.

- [ ] **Step 3: The Vue pages**

`Programme.vue` (public, `AppTopbarLayout` by default): a day switcher (buttons, preselecting the day matching today in the event's zone, else the first), then `<ProgrammeGrid>` on `md:` and up, `<ProgrammeList>` below — one `v-if`/`v-else` on a `useMediaQuery`-style breakpoint, or simply render both with `hidden md:block` / `md:hidden`, which needs no JS. Track filter: a row of toggle buttons that narrows `trackNames`.

`ProgrammeGrid.vue`: a `<table>` — `<th>` per track name, one `<tr>` per row, `<td :rowspan="cell.rowSpan">` per cell, empty `<td>` where a track has nothing starting in that row and is not covered by a span (the builder's `cells` array already says which; do not compute coverage in the template). A cancelled cell renders `line-through` with its reason beneath. Untracked items render above the table in a bordered band headed "Ohne Track".

`ProgrammeList.vue`: the same data flattened — one entry per cell in time order, showing time, title, track name and location.

`NowNextCard.vue`: two lines, "Jetzt" and "Gleich", each a title plus time and location, then a link to the programme; empty state "Gerade läuft nichts". Imports only `@inertiajs/vue3`, `@/lib/datetime` (`formatTime`) and `@/components/ui/*` — `tests/Feature/HubComponentBoundaryTest.php` enforces this.

All participant copy in German. Times through `@/lib/datetime`'s `formatTime`, never `toLocaleTimeString` (`.ai/rules/js.md`, and `tests/Unit/DateTimeRenderingTest.php` fails the build otherwise).

`Edit.vue` (admin, English copy): three cards — Tracks, Locations, Items — each a list with add/remove/reorder buttons (no drag library is installed and none may be added); the item form carries title, description, speaker, track select, location select, a free `location_note`, and two `DateTimeField`s that may each be left empty. Publish and Cancel buttons per item. Copy the form idioms from `app-modules/event/resources/js/pages/admin/events/Edit.vue`.

`Dashboard.vue` (Event): a secondary `Button as-child` + `Link` to `admin.events.agenda.edit`, labelled "Programme", beside the existing "Live hub" button.

- [ ] **Step 4: Build, lint, commit**

```bash
DOCKER_CONTEXT=default vendor/bin/sail composer types:generate
DOCKER_CONTEXT=default vendor/bin/sail npm run build
DOCKER_CONTEXT=default vendor/bin/sail npm run lint
DOCKER_CONTEXT=default vendor/bin/sail npm run types:check
DOCKER_CONTEXT=default vendor/bin/sail vendor/bin/pint --dirty --format agent
git add app-modules/agenda app-modules/event resources/js/types && git commit -m "The programme is published, edited and shown in the hub"
```

---

### Task 5: Capabilities, activation, observability, docs

**Files:**
- Modify: `src/Providers/AgendaServiceProvider.php` (`defaultRoles()`), `src/Enums/Permission.php`
- Create: `src/Metrics/AgendaMetrics.php`, `src/Listeners/Metrics/RecordAgendaMetrics.php`
- Modify: `contrib/observability/src/platform.mjs`, `docs/operations.md`
- Rewrite: `app-modules/agenda/SPEC.md`, `app-modules/agenda/TODO.md`
- Test: `app-modules/agenda/tests/AgendaActivationTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('disappears entirely when the brand has not activated it', function (): void {
    $brand = $this->withBrand();                       // module NOT activated
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->get("/events/{$event->slug}/programm")->assertNotFound();
    $this->actingAs($admin)->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->get("/admin/events/{$event->id}/agenda")->assertNotFound();

    expect(app(Konventix\Platform\Hub\HubRegistry::class)->all()
        ->contains(fn ($item): bool => $item->key === 'agenda.now-next'))->toBeTrue();   // registered at boot…
    expect(app(Konventix\Platform\Hub\HubRegistry::class)
        ->visibleCards(Konventix\Identity\Models\User::factory()->create())
        ->contains(fn ($card): bool => $card->key === 'agenda.now-next'))->toBeFalse();  // …filtered at render (ADR-0003)
});

it('grants ManageAgenda to the Event Admin default role', function (): void {
    expect(collect(app(Konventix\Agenda\Providers\AgendaServiceProvider::class, ['app' => app()])->defaultRoles())
        ->flatMap(fn ($role) => $role->permissions)
        ->contains(Konventix\Agenda\Enums\Permission::ManageAgenda->value))->toBeTrue();
});
```

The second test's construction of the provider is awkward — check how `app-modules/*/tests/DefaultRolesTest.php` (or the platform test that asserts default roles) does it and copy that instead; if no such test exists, assert against the seeded role after `permissions:sync` in a feature test.

- [ ] **Step 2: Capabilities and default role**

`Permission::ManageAgenda = 'agenda.manage-agenda'` (already added in Task 2 — verify it is there and picked up by `permissions:sync`). `defaultRoles()` grants it to the Event Admin role, following the shape in `app-modules/event/src/Providers/EventServiceProvider.php`.

- [ ] **Step 3: Metrics and the panel**

`AgendaMetrics::definitions()` returns one `MetricDefinition('agenda_items_published_total', MetricKind::Counter, 'Programme items published.', ['brand'])`, registered from `registerMetrics()` the way `EventServiceProvider` does. `RecordAgendaMetrics::handleItemPublished(AgendaItemPublished $e)` increments with `['brand' => BrandSlugs::of($e->brandId)]`; register it in `registerListeners()`.

Panel in `contrib/observability/src/platform.mjs`, beside the hub stats:

```js
stat('Programme items published', [prom(`sum(increase(konventix_agenda_items_published_total{${J}}[$__range]))`)], { description: 'Agenda items that became publicly visible (D-AGD-08).' }),
```

Then `DOCKER_CONTEXT=default vendor/bin/sail npm run observability:build`. `tests/Feature/ObservabilityCoverageTest.php` must stay green — you cannot run it, so check by eye that the metric name in the panel matches the catalogue exactly.

- [ ] **Step 4: SPEC and TODO**

Rewrite `app-modules/agenda/SPEC.md`: drop the "post-release, do not start" banner; Owns = `AgendaItem`, `Track`, `Location`, and (half two) the competition selection; the three emitted events with payloads; Consumes = Event's summary events now, Competition's lifecycle events in half two; Capabilities = `ManageAgenda`; Activation as built; and replace the five open questions with the answers from design §12, each pointing at its D-AGD number.

`TODO.md`: Done lines for Tasks 1–5; **Next** = the competition half (D-AGD-01), which needs #296's `starts_at` and lifecycle events; Backlog = personal agendas (Community), item capacity (Registration), speaker accounts, linking a location to a Resources zone.

`docs/operations.md`: a short "Programme" paragraph — the metric, that the public page is cache-free and brand-scoped, and that an empty programme is a planning state, not an incident.

- [ ] **Step 5: Format and commit**

```bash
DOCKER_CONTEXT=default vendor/bin/sail vendor/bin/pint --dirty --format agent
git add app-modules/agenda contrib/observability docs/operations.md && git commit -m "The programme is gated, counted and documented"
```

---

## Self-review against the spec

| Spec section | Task |
|---|---|
| §4.1 tracks, §4.2 items (three time shapes, check constraint), §4.3 locations | 1 |
| §4.2 location resolution, §7 events out, capability | 2 (events, Data, Actions), 3 (resolution), 5 (capability) |
| §5 rows/columns/rowspan, untracked band, multi-day, cancelled visible | 3 (data), 4 (rendering) |
| §6 three surfaces | 4 |
| §8 capability, activation, GDPR (none — no personal data), observability | 5 |
| §9 testing | tests in 1–5 |
| §10 DoD 1–6 | 4 (1, 5), 2 (2, 3), 3 (4), 5 (6) |
| §4.5 competition half (D-AGD-01) | **deliberately not in this plan** — it waits on #296; TODO.md carries it |

**Gap found and closed during review:** the public route needs the event's slug, timezone and days, and Agenda may not read Event's tables. Task 4 Step 2 now specifies an `agenda_event_summaries` projection mirroring Admission's `EventSummary`, with an explicit instruction to stop and report if it grows past ~80 lines rather than improvise.

**Type consistency checked:** `ProgrammeCellData`/`ProgrammeRowData`/`ProgrammeDayData` field names are used identically in Tasks 3 and 4; `AgendaItemData`'s camelCase keys match what the admin form posts; `ItemState` cases are spelled the same in Tasks 1, 2, 3 and 4; the hub card key `agenda.now-next` matches between Task 4's registration and Task 5's activation test.
