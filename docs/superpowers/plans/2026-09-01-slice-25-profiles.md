# Slice 25 — Profiles and the Face per Brand: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every account an operator-wide handle and profile-of-record (`username`, `tagline`, `status_emoji`, `social_links`), a per-brand-membership avatar and banner backed by the brand's media library, a four-field shared `auth.user` prop, and Community's first routed page `u/{username}` with a per-brand profile read model and profile-wall comments/reactions.

**Architecture:** Identity stays the writer of record (new `users` columns, two membership image columns, widened `UserProfileUpdated`, new brand-scoped `BrandMembershipProfileUpdated`); Community is a pure event consumer (two read-model tables, projections, the page, comments). Media integration goes exclusively through Platform's `MediaLibrary` doorway (ADR-0035) plus `StoreMediaUpload`; usernames are taken for granted because the platform is pre-release (ADR-0051 §3).

**Tech Stack:** Laravel 13 / PHP 8.5, InterNACHI modular (`app-modules/<kebab>` → `Konventix\`), Inertia v3 + Vue 3 + Wayfinder, spatie/laravel-data for HTTP payloads, Pennant per-brand gates, Pest 5 on Postgres.

**Spec:** `docs/decisions/0051-profiles-and-the-face-per-brand.md` (ADR-0051, accepted). Supporting: ADR-0004, ADR-0010, ADR-0014, ADR-0016, ADR-0029, ADR-0035, ADR-0038, ADR-0043, `app-modules/identity/SPEC.md` (four-field prop promise), `app-modules/community/SPEC.md` (profile read model), ROADMAP slice 25 + P1.

## Controller rulings baked into this plan

These are decisions where ADR-0051's text and the codebase disagree; they were ruled before task decomposition and the tasks below implement the rulings.

1. **Comments:** slice 22's *comment* table is hard-FK'd to `content_entries` (only its *reaction* subject is a `(module, entity_type, entity_id)` triple). "Comments through slice 22's generic reaction/comment subject" (§7) is implemented as Community-owned `community_comments` + `community_reactions` tables that use the same subject-triple **convention** — which slice 26 (galleries, Community-owned) requires anyway. No shared table with Content.
2. **Member since:** no membership event exists. `BrandMembershipProfileUpdated` carries `firstSeenAt` in addition to the ADR's five named fields — the SPEC's read model shows "member since", and ADR-0004 requires every displayed fact to travel in a replayable payload. `RecordBrandMembership` emits the event on first contact.
3. **Social links:** ADR-0051 §6 lists only `username`, `tagline`, `statusEmoji` as new `UserProfileUpdated` fields, but §7 has Community render the full profile-of-record and Community may not import Identity code (ArchitectureTest). `UserProfileUpdated` therefore also carries `socialLinks` (array of scalars).
4. **`UserRegistered` gains `username`.** A new account has a handle at birth (ADR-0051 §3); without it Community's profile projection could not create a row until the first profile edit or a replay, and `u/{username}` would 404 for fresh accounts.
5. **Projection gating:** the brand-scoped `community_members` projection short-circuits per the *event's* brand (Pennant `module:community`, checked via `Feature::for($brand)`, never via request-bound `ModuleGate::isActive()` — replays run with no request). The operator-wide `community_profiles` projection is **not** gated: accounts span the operator and the data belongs to no brand. Brands enabling Community late are backfilled by `identity:replay-profiles` (documented in the SPEC).
6. **Moderation scope:** profile-wall comments get delete-by-author-or-wall-owner only. Content's report/moderation state machine is editorial machinery and is not copied. No contract events for community comments yet (no consumer).
7. **All new event constructor parameters are required (no defaults)** so every dispatch site that forgets one fails loudly instead of silently projecting nulls.

## Global Constraints

Copied from the spec and the repo's standing rules — every task's requirements include these:

- **Username format (verbatim ADR-0051 §3):** `^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$` (3–32 chars, lowercase, digits, inner hyphens). Reserved list refused at validation: `admin`, `konventix`, `operator`, `api`, `www`. Unique operator-wide, case-insensitive: unique index on `lower(username)`, stored as typed, compared folded.
- **Tombstone (ADR-0051 §9):** on anonymization `username` becomes `deleted-<ulid>` (lowercased ULID) — a system write exempt from the input format's length cap; `tagline`, `status_emoji`, `social_links` become null.
- **Tagline hard cap 140 characters; `status_emoji` one emoji grapheme; `social_links` keys fixed to `steam, discord, twitch, youtube, x, instagram, website`.**
- ADR-0004: never import another module's Eloquent model (Platform primitives `Brand`/`Role`/`Permission`/`Concerns\*`/`Scopes\*` excepted); events carry IDs and scalars only; foreign display data lives in own-module read models.
- ADR-0010/0029: brand-owned models use `BelongsToBrand`; never `where('brand_id', …)` as scoping; the only escape is `withoutBrandScope()`; on the admin host no brand resolves and BrandScope hardens to zero rows.
- ADR-0014: ULID PKs everywhere; cross-module reference columns are plain `ulid` with **no** FK constraint; same-module FKs are real.
- ADR-0035/0043: media is referenced only through `Konventix\Platform\Media\MediaLibrary` (`urlFor`, `belongsToBrand`, `recordUsage`, `releaseUsage`) and ingested only through `Konventix\Platform\Actions\StoreMediaUpload`; never import `Konventix\Platform\Models\MediaItem` from another module.
- Listeners register explicitly with `Event::listen` in the module's ServiceProvider; discovery is off (.ai/rules/listeners.md). Module route files live in `routes/web/`, declared bare (no `web`, no gate — the provider wraps them; community is gateable so `ensure-module:community` is automatic).
- Models outside `App\Models` wire factories in both directions (`newFactory()` + `protected $model`); factories/migrations/seeders live inside the module.
- Tests: never `Event::fake()` without arguments; run inside the container (`docker compose exec -T laravel.test php artisan test --compact`); never two test processes at once; a new Vue page needs `npm run build` first (run `npm run build` inside the container or on the host before feature tests that render new pages); Pest helper functions are process-global — prefix them per file.
- Frontend: semantic tokens only (no palette utilities — ESLint fails the build); module pages live in `app-modules/<name>/resources/js/pages`; keyboard reachability (icon-only buttons need `aria-label`); stateful `useForm` edit forms need the ADR-0038 unsaved-changes guard.
- PHP: `final` controllers/actions/listeners, constructor promotion, explicit return types, curly braces always; run `vendor/bin/pint --dirty --format agent` (in the container) before every commit.
- Commit style: imperative subject lines like the existing history; commit after each green test cycle.

**Container invocations** (host ports are remapped on this machine): `docker compose exec -T laravel.test php artisan test --compact …`, `docker compose exec -T laravel.test vendor/bin/pint --dirty --format agent`. If the stack is down: `WWWUSER=$(id -u) WWWGROUP=$(id -g) FORWARD_DB_PORT=15432 FORWARD_REDIS_PORT=16379 VITE_PORT=15173 APP_PORT=18080 docker compose up -d pgsql redis laravel.test`.

---

### Task 1: Username foundations — generator, migration, model, factory, seeders, signup

**Files:**
- Create: `app-modules/identity/src/Profile/UsernameGenerator.php`
- Create: `app-modules/identity/database/migrations/2026_09_01_150000_add_profile_of_record_to_users_table.php`
- Modify: `app-modules/identity/src/Models/User.php`
- Modify: `app-modules/identity/database/factories/UserFactory.php`
- Modify: `database/seeders/DatabaseSeeder.php`, `database/seeders/Demo/DemoRushSeeder.php`
- Modify: `app-modules/identity/src/Actions/CreateNewUser.php`, `app-modules/identity/src/Actions/CompleteSocialSignup.php`
- Test: `app-modules/identity/tests/UsernameGeneratorTest.php`, `app-modules/identity/tests/UsernameColumnTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `UsernameGenerator::generate(string $displayName): string`; `UsernameGenerator::RESERVED` (`array`), `UsernameGenerator::FORMAT` (`'/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/'`); columns `users.username` (string 64, NOT NULL, unique on `lower(username)`), `users.tagline` (string 140 nullable), `users.status_emoji` (string 32 nullable), `users.social_links` (json nullable); `User` casts `social_links` to `array`; `UserFactory` generates unique usernames.

- [ ] **Step 1: Write failing unit tests for the generator**

```php
<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;

it('slugs a display name into the username format', function (): void {
    expect(app(UsernameGenerator::class)->generate('Ada Lovelace'))->toBe('ada-lovelace');
});

it('suffixes on collision, case-insensitively', function (): void {
    User::factory()->create(['username' => 'ada-lovelace']);

    expect(app(UsernameGenerator::class)->generate('Ada LOVELACE'))->toBe('ada-lovelace-2');
});

it('never emits a reserved name', function (): void {
    expect(app(UsernameGenerator::class)->generate('Admin'))->toBe('admin-2');
});

it('falls back for names that slug away entirely', function (): void {
    expect(app(UsernameGenerator::class)->generate('莫'))->toStartWith('member');
});

it('always satisfies the format', function (string $name): void {
    expect(app(UsernameGenerator::class)->generate($name))->toMatch(UsernameGenerator::FORMAT);
})->with(['Ada Lovelace', 'X Æ A-12', '--- --', 'a', 'A very long name that would exceed the thirty-two character cap easily']);
```

- [ ] **Step 2: Run to verify failure** — `docker compose exec -T laravel.test php artisan test --compact app-modules/identity/tests/UsernameGeneratorTest.php` → FAIL (class not found; the collision test also needs the column, added in Step 3 — run again after Step 4).

- [ ] **Step 3: Write the migration** (backfill inline, model-free; index + NOT NULL via `DB::statement` — Postgres, precedent `2026_09_01_100000_widen_data_subject_requests_for_operator_scope.php`):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The profile-of-record (ADR-0051 §2–3). `username` ships NOT NULL because
 * the platform is pre-release: the backfill below serves dev fixtures and
 * nothing else, so there is no claim flow. Unique case-insensitively via an
 * expression index — stored as typed, compared folded.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $reserved = ['admin', 'konventix', 'operator', 'api', 'www'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 64)->nullable()->after('name');
            $table->string('tagline', 140)->nullable()->after('username');
            $table->string('status_emoji', 32)->nullable()->after('tagline');
            $table->json('social_links')->nullable()->after('status_emoji');
        });

        $used = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $row) {
            $base = (string) Str::of($row->name)->ascii()->lower()
                ->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->substr(0, 24)->trim('-');

            if (strlen($base) < 3) {
                $base = 'member';
            }

            $candidate = $base;
            $suffix = 2;

            while (isset($used[$candidate]) || in_array($candidate, $this->reserved, true)) {
                $candidate = $base.'-'.$suffix++;
            }

            $used[$candidate] = true;

            DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
        }

        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'tagline', 'status_emoji', 'social_links']);
        });
    }
};
```

- [ ] **Step 4: Write the generator**

```php
<?php

declare(strict_types=1);

namespace Konventix\Identity\Profile;

use Illuminate\Support\Str;
use Konventix\Identity\Models\User;

/**
 * Derives a free handle from a display name (ADR-0051 §3). The database's
 * unique index on lower(username) is the final guard; this class only makes
 * collisions unlikely, it does not lock.
 */
final class UsernameGenerator
{
    public const string FORMAT = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/';

    /** @var list<string> */
    public const array RESERVED = ['admin', 'konventix', 'operator', 'api', 'www'];

    public function generate(string $displayName): string
    {
        $base = (string) Str::of($displayName)->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->substr(0, 24)->trim('-');

        if (strlen($base) < 3) {
            $base = 'member';
        }

        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, self::RESERVED, true) || $this->taken($candidate)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function taken(string $candidate): bool
    {
        return User::query()->whereRaw('lower(username) = ?', [$candidate])->exists();
    }
}
```

- [ ] **Step 5: Update model, factory, seeders, signup actions**

`User.php`: extend `#[Fillable]` with `'username', 'tagline', 'status_emoji', 'social_links'`; add `'social_links' => 'array'` to `casts()`; extend the `@property` docblock (`string $username`, `?string $tagline`, `?string $status_emoji`, `?array $social_links`).

`UserFactory::definition()` gains (before the two-factor nulls):
```php
'username' => 'u'.Str::lower(Str::random(14)),
'tagline' => null,
'status_emoji' => null,
'social_links' => null,
```

`DatabaseSeeder`: add `'username' => 'test-user'` to the `test@example.com` attribute array and `'username' => 'operator-admin'` to the admin one. `DemoRushSeeder`: add `'username' => "rush-arrival-{$n}"` to the factory attribute array.

`CreateNewUser` and `CompleteSocialSignup`: inject/resolve `UsernameGenerator` and add `'username' => $this->usernames->generate($input['name'])` (resp. the social display name) to the `User::create([...])` array. Match each file's existing DI style (constructor promotion where a constructor exists, `app(UsernameGenerator::class)` otherwise).

- [ ] **Step 6: Sweep remaining creation sites** — `grep -rn "User::create(" app app-modules database` and fix any site not covered above to include a `username` (tests included). Then write `UsernameColumnTest.php`:

```php
it('refuses a second username differing only by case', function (): void {
    User::factory()->create(['username' => 'ada']);

    expect(fn () => User::factory()->create(['username' => 'ADA']))
        ->toThrow(Illuminate\Database\QueryException::class);
})
```
(Note: `ADA` violates the input format, but the column stores what it is given — the index, not validation, is under test here. A constraint violation aborts the transaction; assert nothing DB-side afterwards.) Add a test that a registration through `POST /register` produces a user whose `username` matches `UsernameGenerator::FORMAT` (use the existing registration test file's conventions; `$this->withBrand()` first).

- [ ] **Step 7: Run the module suite, then the full suite** — `docker compose exec -T laravel.test php artisan test --compact app-modules/identity/tests/` → PASS, then the full suite (SSR must be running for `SettingsLayoutTest`; if exactly those 10 fail, rebuild SSR and restart `artisan inertia:start-ssr`).

- [ ] **Step 8: Pint + commit** — `git add -A && git commit -m "Give every account a handle and the profile-of-record columns"`.

---

### Task 2: Widen the contract events and the replay

**Files:**
- Modify: `app-modules/identity/src/Events/UserProfileUpdated.php`, `app-modules/identity/src/Events/UserRegistered.php`
- Modify: every dispatch site — grep `new UserProfileUpdated(` and `new UserRegistered(` across `app/`, `app-modules/`, `database/` (known: `ProfileController.php`, `UpdateUserAccount.php`, `ReplayProfilesCommand.php`, `database/seeders/Demo/DemoUserSeeder.php`, plus the Identity dispatch site(s) of `UserRegistered` the grep finds)
- Modify: tests constructing the events directly — `app-modules/messaging/tests/RecipientReadModelTest.php`, `app-modules/content/tests/ContentPublicTest.php`, `app-modules/identity/tests/UserContractEventsTest.php`, plus anything else the grep finds
- Test: extend `app-modules/identity/tests/UserContractEventsTest.php`

**Interfaces:**
- Produces (exact, all params required — ruling 7):

```php
final readonly class UserProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $displayName,
        public string $username,
        public ?string $tagline,
        public ?string $statusEmoji,
        /** @var array<string, string> */
        public array $socialLinks,
        public string $locale,
        public string $updatedAt,
    ) {}
}
```
`UserRegistered` gains `public string $username` directly after `displayName` (all other fields unchanged).

- [ ] **Step 1: Extend `UserContractEventsTest`** — the existing profile-update test's `assertDispatched` closure additionally asserts `$event->username === $user->username && $event->tagline === null && $event->socialLinks === []`; add one registration-path test asserting `UserRegistered::$username` matches the created user's. Run → FAIL (unknown named args).
- [ ] **Step 2: Widen both event classes** (keep the docblock sentences; append to `UserProfileUpdated`'s docblock: "Widened by ADR-0051 §6: the profile-of-record travels here so per-module read models can render it without reading Identity.").
- [ ] **Step 3: Update all dispatch sites.** Every dispatch passes the row's current values, e.g. in `ProfileController::update` / `UpdateUserAccount` / `ReplayProfilesCommand`:

```php
username: $user->username,
tagline: $user->tagline,
statusEmoji: $user->status_emoji,
socialLinks: $user->social_links ?? [],
```
`DemoUserSeeder::announce()` adds `username: $user->username` to its `UserRegistered`. The production `UserRegistered` dispatch site(s) found by grep get the same.
- [ ] **Step 4: Fix direct-construction tests** found by grep (named args; give the new fields honest values).
- [ ] **Step 5: Run affected suites** — identity, messaging, content, registration module tests, then full suite. PASS.
- [ ] **Step 6: Pint + commit** — `git commit -m "The profile-of-record travels in the account contract events"`.

---

### Task 3: Profile-of-record settings — validation and form

**Files:**
- Create: `app-modules/identity/src/Profile/SocialLinks.php`
- Modify: `app-modules/identity/src/Data/ProfileUpdateData.php`
- Modify: `app-modules/identity/src/Http/Controllers/ProfileController.php`
- Modify: `resources/js/pages/settings/Profile.vue`
- Test: `app-modules/identity/tests/ProfileOfRecordTest.php`

**Interfaces:**
- Consumes: Task 1 columns + generator constants; Task 2 event shape.
- Produces: `SocialLinks::PLATFORMS` (`array<string, string>` platform → regex, excluding `website`); `ProfileUpdateData` with `public readonly string $username`, `?string $tagline`, `?string $statusEmoji`, `?array $socialLinks`; `ProfileController::edit` passes a `profile` prop `{name, email, username, tagline, statusEmoji, socialLinks}` (Task 6 depends on the page no longer reading `auth.user` fields).

**Do NOT widen `ProfileValidationRules::profileRules()`** — it is shared with `RegisterData`/`CompleteSocialSignupData` and registration must not ask for a username (ADR-0051 §3: taken for granted). Add rules in `ProfileUpdateData::rules()` only.

- [ ] **Step 1: Write the failing tests** (representative set — implement all):

```php
it('updates the profile-of-record', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => $user->name, 'email' => $user->email,
        'username' => 'ada-lovelace', 'tagline' => 'First programmer',
        'statusEmoji' => '🚀',
        'socialLinks' => ['x' => 'ada', 'website' => 'https://example.test'],
    ])->assertRedirect();

    $user->refresh();
    expect($user->username)->toBe('ada-lovelace')
        ->and($user->social_links)->toBe(['x' => 'ada', 'website' => 'https://example.test']);
});

it('refuses a reserved username', ...);                 // 'admin' → assertInvalid(['username'])
it('refuses a taken username case-insensitively', ...); // other user 'ada', submit 'ADA' → invalid (format) AND submit 'ada' → invalid (taken)
it('refuses a 141-character tagline', ...);
it('refuses more than one emoji grapheme and refuses plain ascii', ...); // '🚀🚀' and 'x' both invalid
it('refuses an unknown social platform key', ...);      // ['myspace' => 'x'] → assertInvalid(['socialLinks'])
it('refuses a malformed platform handle', ...);         // ['x' => 'has spaces'] → invalid
it('keeps username unchanged when resubmitted by its owner', ...); // no false "taken"
```

- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement `SocialLinks`**

```php
final class SocialLinks
{
    /** Platform => handle shape. `website` is validated as a URL instead. */
    public const array PLATFORMS = [
        'steam' => '/^[A-Za-z0-9_-]{2,32}$/',
        'discord' => '/^[a-z0-9_.]{2,32}$/',
        'twitch' => '/^[A-Za-z0-9_]{3,25}$/',
        'youtube' => '/^@?[A-Za-z0-9_.\-]{3,30}$/',
        'x' => '/^[A-Za-z0-9_]{1,15}$/',
        'instagram' => '/^[A-Za-z0-9_.]{1,30}$/',
    ];

    public const array KEYS = ['steam', 'discord', 'twitch', 'youtube', 'x', 'instagram', 'website'];
}
```

- [ ] **Step 4: Widen `ProfileUpdateData`** — new ctor `(string $name, string $email, string $username, ?string $tagline, ?string $statusEmoji, ?array $socialLinks)`; `rules()` merges `self::profileRules($userId)` with:

```php
'username' => [
    'required', 'string', 'regex:'.UsernameGenerator::FORMAT,
    Rule::notIn(UsernameGenerator::RESERVED),
    function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
        $taken = User::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower((string) $value)])
            ->when($userId !== null, fn ($query) => $query->whereKeyNot($userId))
            ->exists();

        if ($taken) {
            $fail(__('This username is already taken.'));
        }
    },
],
'tagline' => ['nullable', 'string', 'max:140'],
'statusEmoji' => ['nullable', 'string', 'max:32',
    function (string $attribute, mixed $value, Closure $fail): void {
        if ($value === null || $value === '') {
            return;
        }

        if (grapheme_strlen((string) $value) !== 1 || preg_match('/^[\x00-\x7F]$/u', (string) $value) === 1) {
            $fail(__('Must be a single emoji.'));
        }
    },
],
'socialLinks' => ['nullable', 'array:'.implode(',', SocialLinks::KEYS)],
'socialLinks.website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
// plus one line per PLATFORMS entry:
'socialLinks.'.$platform => ['sometimes', 'nullable', 'string', 'regex:'.$regex],
```
(Build the per-platform lines with a loop over `SocialLinks::PLATFORMS` in `rules()`.)

- [ ] **Step 5: Update `ProfileController`.** `edit()` adds
```php
'profile' => [
    'name' => $user->name, 'email' => $user->email,
    'username' => $user->username, 'tagline' => $user->tagline,
    'statusEmoji' => $user->status_emoji, 'socialLinks' => $user->social_links ?? (object) [],
],
```
`update()` fills `username`, `tagline` (empty string → null), `status_emoji`, `social_links` (drop empty values with `array_filter`; store `null` when the result is `[]`), then dispatches the widened event (already required by Task 2's compile).
- [ ] **Step 6: Extend `resources/js/pages/settings/Profile.vue`** — read the new `profile` page prop (typed `defineProps`) instead of `auth.user` for default values (leave the `auth.user` read for now only if removing it breaks the page — Task 6 deletes it for good); add inputs for `username` (with format hint text), `tagline` (with a character counter is optional — a `maxlength="140"` attribute suffices), `statusEmoji`, and the seven social fields (name them `socialLinks[steam]` etc. so the `<Form>` component nests them); each gets an `<InputError>`; all copy through `__()`-less plain English like sibling fields; semantic tokens only.
- [ ] **Step 7: Build + run** — `npm run build`, then the test file, then the identity suite. PASS.
- [ ] **Step 8: Pint + commit** — `git commit -m "Let a person say who they are: the profile-of-record form"`.

---

### Task 4: Membership images — columns, event, upload, removal, replay

**Files:**
- Create: `app-modules/identity/database/migrations/2026_09_01_151000_add_profile_images_to_brand_memberships.php`
- Create: `app-modules/identity/src/Events/BrandMembershipProfileUpdated.php`
- Create: `app-modules/identity/src/Actions/UpdateMembershipImage.php`
- Create: `app-modules/identity/src/Http/Controllers/MembershipImageController.php`
- Modify: `app-modules/identity/src/Models/BrandMembership.php`, `app-modules/identity/src/Listeners/RecordBrandMembership.php`, `app-modules/identity/src/Console/ReplayProfilesCommand.php`, `app-modules/identity/routes/web/identity.php`, `app-modules/identity/src/Http/Controllers/ProfileController.php`, `resources/js/pages/settings/Profile.vue`
- Test: `app-modules/identity/tests/MembershipImageTest.php`

**Interfaces:**
- Consumes: `StoreMediaUpload::handle(string $brandId, UploadedFile $file, ?string $altText, ?string $uploadedByUserId)` (returns the media model — call `->getKey()` on it, never import its class); `MediaLibrary::{urlFor, recordUsage, releaseUsage}`; `BrandContext::currentOrFail()`.
- Produces (exact):

```php
final readonly class BrandMembershipProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $brandId,
        public ?string $avatarUrl,
        public ?string $bannerUrl,
        public string $firstSeenAt,   // ISO-8601; ruling 2 — "member since" must be replayable
        public string $updatedAt,
    ) {}
}
```
`UpdateMembershipImage::set(User $user, string $kind, UploadedFile $file): void`, `::remove(User $user, string $kind): void`, `::pointAt(User $user, string $kind, string $mediaId): void` where `$kind` ∈ `'avatar'|'banner'`. Routes: `POST/DELETE settings/profile/avatar` (`profile.avatar.store` / `.destroy`), `POST/DELETE settings/profile/banner` (`profile.banner.store` / `.destroy`). Usage triple: `('identity', 'membership', $membership->getKey())`. `ProfileController::edit` adds `'membershipImages' => ['avatarUrl' => ?string, 'bannerUrl' => ?string]`.

- [ ] **Step 1: Migration** — two nullable ULID columns, no FK (cross-module reference, ADR-0014):

```php
Schema::table('brand_memberships', function (Blueprint $table): void {
    // Brand-owned media referenced across the module boundary — plain
    // ULIDs, no constraint (ADR-0014; ADR-0051 §1).
    $table->ulid('avatar_media_id')->nullable()->after('preferences');
    $table->ulid('banner_media_id')->nullable()->after('avatar_media_id');
});
```
`BrandMembership`: add both to `$fillable`, docblock properties.

- [ ] **Step 2: Failing tests** (all with `$this->withBrand()`, `Storage::fake('media')`, acting as a user who has a membership — create via `BrandMembership::factory()->create(['user_id' => $user->getKey()])`):

```php
it('stores an uploaded avatar in the brand library and points the membership at it', function (): void {
    // POST /settings/profile/avatar with UploadedFile::fake()->image('face.png', 400, 400)
    // assert: membership->avatar_media_id set; MediaUsage row for
    // ('identity','membership',$membership->id) exists (query via a helper that
    // reads the media_usages table with DB::table — Identity tests may not import
    // Platform's MediaUsage model? They MAY: Pest test files are exempt from
    // ArchitectureTest. Use MediaUsage::withoutBrandScope() directly.)
});
it('emits BrandMembershipProfileUpdated with both current urls', ...);      // Event::fake([BrandMembershipProfileUpdated::class]) BEFORE the post
it('releases the old usage when the avatar is replaced', ...);
it('removes the avatar and releases its usage', ...);                        // DELETE route; avatar_media_id null; event emitted with avatarUrl null
it('rejects a pdf', ...);                                                    // assertInvalid(['file'])
it('emits the membership event when a member first arrives', function (): void {
    // Event::fake([BrandMembershipProfileUpdated::class]);
    // fire Illuminate\Auth\Events\Login with a fresh user under withBrand()
    // assert dispatched with null urls and firstSeenAt === membership first_seen_at ISO string
});
it('replays one membership event per membership', ...);                      // create user w/ membership, Event::fake([...]), artisan identity:replay-profiles, assertDispatched
```

- [ ] **Step 3: Run → FAIL.**
- [ ] **Step 4: Implement the action** (single source for set/remove/pointAt so the Steam copy in Task 5 reuses it):

```php
final class UpdateMembershipImage
{
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly StoreMediaUpload $uploads,
    ) {}

    public function set(User $user, string $kind, UploadedFile $file): void
    {
        $brandId = app(BrandContext::class)->currentOrFail()->getKey();

        $item = $this->uploads->handle(
            brandId: $brandId,
            file: $file,
            altText: null,
            uploadedByUserId: $user->getKey(),
        );

        $this->pointAt($user, $kind, $item->getKey());
    }

    public function pointAt(User $user, string $kind, string $mediaId): void
    {
        $membership = $this->membershipFor($user);
        $column = $kind.'_media_id';
        $previous = $membership->{$column};

        if (! $this->media->belongsToBrand($mediaId, $membership->brand_id)) {
            throw ValidationException::withMessages(['file' => __('Choose an image from this brand.')]);
        }

        $this->media->recordUsage($mediaId, 'identity', 'membership', $membership->getKey());
        $membership->forceFill([$column => $mediaId])->save();

        if ($previous !== null && $previous !== $mediaId) {
            $this->media->releaseUsage($previous, 'identity', 'membership', $membership->getKey());
        }

        $this->announce($membership);
    }

    public function remove(User $user, string $kind): void
    {
        $membership = $this->membershipFor($user);
        $column = $kind.'_media_id';

        if ($membership->{$column} !== null) {
            $this->media->releaseUsage($membership->{$column}, 'identity', 'membership', $membership->getKey());
            $membership->forceFill([$column => null])->save();
        }

        $this->announce($membership);
    }

    private function membershipFor(User $user): BrandMembership
    {
        $membership = BrandMembership::query()->where('user_id', $user->getKey())->first();

        if ($membership === null) {
            // A face is shown to a brand's community; without a membership
            // there is no community to show it to.
            throw ValidationException::withMessages(['file' => __('You are not a member of this brand yet.')]);
        }

        return $membership;
    }

    private function announce(BrandMembership $membership): void
    {
        event(new BrandMembershipProfileUpdated(
            userId: $membership->user_id,
            brandId: $membership->brand_id,
            avatarUrl: $this->urlOrNull($membership->avatar_media_id),
            bannerUrl: $this->urlOrNull($membership->banner_media_id),
            firstSeenAt: $membership->first_seen_at->toIso8601String(),
            updatedAt: now()->toIso8601String(),
        ));
    }

    private function urlOrNull(?string $mediaId): ?string
    {
        return $mediaId === null ? null : ($this->media->urlFor($mediaId, 'thumb') ?? $this->media->urlFor($mediaId));
    }
}
```
(Resolve `BrandContext` at call time, never in the constructor — it is a scoped binding.)

- [ ] **Step 5: Controller + routes.** `final class MembershipImageController` with `storeAvatar`, `destroyAvatar`, `storeBanner`, `destroyBanner` — validation inline like `MediaAdminController`: avatar `['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp']`, banner same with `max:10240`; each calls the action, flashes a toast (`Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile image updated.')])`), `return to_route('profile.edit')`. Routes go in the existing `auth` group in `identity.php`.
- [ ] **Step 6: Emit on first contact** — `RecordBrandMembership::handle` captures `firstOrCreate`'s result; when `wasRecentlyCreated`, dispatch the event with null URLs. **Replay** — inside the per-user loop of `ReplayProfilesCommand`, after the profile event:

```php
foreach (BrandMembership::withoutBrandScope()->where('user_id', $user->getKey())->get() as $membership) {
    event(new BrandMembershipProfileUpdated(/* same shape, urls resolved via MediaLibrary */));
}
```
(inject/resolve `MediaLibrary` in the command; extract a small shared private `urlOrNull` there too).
- [ ] **Step 7: `ProfileController::edit`** adds `membershipImages` (resolve the current brand membership's two URLs; both null when no brand/membership). **`Profile.vue`** gains a "Profile images" section: current avatar/banner previews (plain `<img>` with `alt=""` in a decorative frame, `max-width` constrained), file inputs posting to the Wayfinder actions on change, and Remove buttons (each a small standalone form; icon-only buttons need `aria-label`).
- [ ] **Step 8: Build, run the test file, identity suite, `pint`, commit** — `git commit -m "A face per brand: membership avatars and banners"`.

---

### Task 5: The Steam avatar is copied, once

**Files:**
- Create: `app-modules/identity/src/Actions/CopySteamAvatar.php`
- Modify: `app-modules/identity/src/Http/Controllers/MembershipImageController.php` (add `copySteam`), `app-modules/identity/routes/web/identity.php` (`POST settings/profile/avatar/steam` → `profile.avatar.steam`), `app-modules/identity/src/Http/Controllers/ProfileController.php` (`edit()` adds `'steamAvatarUrl' => ?string`), `resources/js/pages/settings/Profile.vue`
- Test: `app-modules/identity/tests/CopySteamAvatarTest.php`

**Interfaces:**
- Consumes: `SocialIdentity` (`provider === 'steam'`, `avatar_url`), `UpdateMembershipImage::pointAt`, `StoreMediaUpload`.
- Produces: `CopySteamAvatar::handle(User $user): void`.

- [ ] **Step 1: Failing tests** — `Http::fake(['*' => Http::response(file_get_contents(base_path('tests/fixtures/... a tiny real png — generate one with imagecreatetruecolor in the test instead of a fixture file')), 200, ['Content-Type' => 'image/png'])])`; user with linked steam identity (`SocialIdentity` factory or `$user->socialIdentities()->create([...])`) and a membership; `POST /settings/profile/avatar/steam` → membership `avatar_media_id` set, media item exists on the brand disk, event emitted. Negative tests: no steam identity → validation error; steam identity without `avatar_url` → validation error; upstream 404 → validation error, membership untouched.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement**

```php
final class CopySteamAvatar
{
    public function __construct(
        private readonly UpdateMembershipImage $images,
        private readonly StoreMediaUpload $uploads,
    ) {}

    public function handle(User $user): void
    {
        $identity = $user->socialIdentities()->where('provider', 'steam')->first();

        if ($identity === null || $identity->avatar_url === null) {
            throw ValidationException::withMessages(['steam' => __('No Steam avatar is linked to this account.')]);
        }

        try {
            $response = Http::timeout(10)->get($identity->avatar_url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $mime = $response->header('Content-Type');

        if (! $response->successful() || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw ValidationException::withMessages(['steam' => __('The Steam avatar could not be fetched.')]);
        }

        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $path = tempnam(sys_get_temp_dir(), 'steam-avatar');
        file_put_contents($path, $response->body());

        try {
            $file = new UploadedFile($path, 'steam-avatar.'.$extension, $mime, null, true);

            $item = $this->uploads->handle(
                brandId: app(BrandContext::class)->currentOrFail()->getKey(),
                file: $file,
                altText: null,
                uploadedByUserId: $user->getKey(),
            );

            $this->images->pointAt($user, 'avatar', $item->getKey());
        } finally {
            @unlink($path);
        }
    }
}
```
Controller method `copySteam(Request $request, CopySteamAvatar $copy)`: call, toast, `to_route('profile.edit')`.
- [ ] **Step 4: `Profile.vue`** — show a "Use my Steam avatar" button in the images section only when `steamAvatarUrl` is non-null (plain `router.post` to the Wayfinder action).
- [ ] **Step 5: Build, test file green, identity suite green, pint, commit** — `git commit -m "Copy the Steam avatar into the brand library, once"`.

---

### Task 6: The shared auth.user prop becomes four fields

**Files:**
- Create: `app-modules/identity/src/Profile/AvatarUrlResolver.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/auth.ts`, `resources/js/components/UserInfo.vue`, `resources/js/components/UserMenuContent.vue`, `resources/js/components/NavUser.vue`, `resources/js/components/AppTopbar.vue` (only if types force it), `app-modules/messaging/resources/js/components/NotificationBell.vue`, `resources/js/pages/settings/Profile.vue` (drop the last `auth.user` read)
- Test: `tests/Feature/SharedAuthPropTest.php` (new), plus keep `app-modules/commerce/tests/ShopPageTest.php` green unchanged

**Interfaces:**
- Consumes: Task 4 columns + `MediaLibrary::urlFor`.
- Produces: shared prop `auth.user` = `null | {id: string, name: string, avatarUrl: string|null, locale: string}`; `AvatarUrlResolver::forUser(string $userId): ?string`; TS `AuthenticatedUser` type in `resources/js/types/auth.ts` (exported alias `User` removed — update importers).

- [ ] **Step 1: Failing test**

```php
it('shares exactly the four promised fields', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.id', $user->getKey())
        ->where('auth.user.name', $user->name)
        ->where('auth.user.locale', 'en')
        ->where('auth.user.avatarUrl', null)
        ->missing('auth.user.email'));
});

it('resolves the avatar from the current brand membership', ...); // membership with avatar_media_id (MediaItem factory) → avatarUrl is the media URL
it('stays null for guests', ...);                                  // where('auth.user', null)
```

- [ ] **Step 2: Run → FAIL** (email present today).
- [ ] **Step 3: Implement the resolver** (Identity — the host middleware calls it; console shows initials because no brand resolves, ADR-0029):

```php
final class AvatarUrlResolver
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function forUser(string $userId): ?string
    {
        if (app(BrandContext::class)->current() === null) {
            return null;
        }

        $mediaId = BrandMembership::query()->where('user_id', $userId)->value('avatar_media_id');

        if ($mediaId === null) {
            return null;
        }

        return $this->media->urlFor($mediaId, 'thumb') ?? $this->media->urlFor($mediaId);
    }
}
```

- [ ] **Step 4: Middleware.** Replace the `auth` entry:

```php
'auth' => [
    // The four-field shape Identity's SPEC promises (ADR-0051 §5):
    // ULID, display name, avatar URL, locale — never the whole model.
    'user' => $request->user() === null ? null : [
        'id' => (string) $request->user()->getAuthIdentifier(),
        'name' => $request->user()->name,
        'avatarUrl' => app(AvatarUrlResolver::class)->forUser((string) $request->user()->getAuthIdentifier()),
        'locale' => $request->user()->locale,
    ],
],
```

- [ ] **Step 5: Frontend sweep.** `types/auth.ts` → `export type AuthenticatedUser = { id: string; name: string; avatarUrl: string | null; locale: string }; export type Auth = { user: AuthenticatedUser | null };` (delete the old `User` type; fix importers). `UserInfo.vue`: prop type `AuthenticatedUser`; `showAvatar` reads `avatarUrl`; delete the email line and the `showEmail` prop. `UserMenuContent.vue`: stop passing `show-email`. `NotificationBell.vue`: keep the `String(id)` conversion but simplify its comment (id is now a string by contract). `settings/Profile.vue`: remove the `page.props.auth.user` read entirely (defaults come from the `profile` prop since Task 3). Grep `auth.user` and `showEmail` across `resources/js` and `app-modules/*/resources/js` to confirm nothing else reads removed fields; run `npx vue-tsc --noEmit` in the container if configured in CI (`npm run` scripts list it) — or rely on the build.
- [ ] **Step 6: Build (`npm run build` + rebuild SSR: `npm run build:ssr`, restart `artisan inertia:start-ssr`), run the new test file, `ShopPageTest`, the full suite.** PASS.
- [ ] **Step 7: Pint + commit** — `git commit -m "The shared auth prop keeps Identity's four-field promise"`.

---

### Task 7: Community read models and projections

**Files:**
- Create: `app-modules/community/database/migrations/2026_09_01_152000_create_community_profiles_and_members.php`
- Create: `app-modules/community/src/Models/CommunityProfile.php`, `app-modules/community/src/Models/CommunityMember.php`
- Create: `app-modules/community/src/Listeners/ProjectCommunityProfile.php`, `app-modules/community/src/Listeners/ProjectCommunityMember.php`
- Modify: `app-modules/community/src/Providers/CommunityServiceProvider.php`
- Test: `app-modules/community/tests/ProfileProjectionTest.php`

**Interfaces:**
- Consumes: `Konventix\Identity\Events\{UserRegistered, UserProfileUpdated, BrandMembershipProfileUpdated}` (exact shapes from Tasks 2 and 4); Platform primitives `Brand`, `ModuleGate::featureName('community')` (= `module:community`), `Laravel\Pennant\Feature`.
- Produces: `CommunityProfile` (table `community_profiles`, PK = user ULID from the event, NOT brand-scoped, NOT `HasUlids`, no factory — rows exist only through the projection, Author-model precedent; fillable `id, username, display_name, tagline, status_emoji, social_links`; `social_links` cast `array`); `CommunityMember` (table `community_members`, `BelongsToBrand` + `HasUlids`, fillable `user_id, avatar_url, banner_url, member_since`, `member_since` cast `datetime`, unique `(user_id, brand_id)`, no factory). Task 8/9/10 read these.

- [ ] **Step 1: Migration** — mirror the two docblocks' reasoning:

```php
Schema::create('community_profiles', function (Blueprint $table): void {
    // PK is the user's own ULID, assigned from the event (Author-model
    // precedent). Not brand-scoped: accounts span the operator (ADR-0016).
    $table->ulid('id')->primary();
    $table->string('username', 64);
    $table->string('display_name');
    $table->string('tagline', 140)->nullable();
    $table->string('status_emoji', 32)->nullable();
    $table->json('social_links')->nullable();
    $table->timestamps();
});

DB::statement('CREATE INDEX community_profiles_username_lower ON community_profiles (lower(username))');

Schema::create('community_members', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();
    // Identity's account — plain ULID, no FK (ADR-0014).
    $table->ulid('user_id')->index();
    $table->string('avatar_url')->nullable();
    $table->string('banner_url')->nullable();
    $table->timestamp('member_since');
    $table->timestamps();

    $table->unique(['user_id', 'brand_id']);
});
```
(`down()`: drop both tables; the index dies with its table.)

- [ ] **Step 2: Failing tests** — emit events, assert rows (tests emit Identity events instead of inventing rows — projection-test precedent). Cases: `UserRegistered` creates a profile row with username+display name; `UserProfileUpdated` upserts all five fields; replay converges (emit twice, one row); `BrandMembershipProfileUpdated` creates a member row **only when** `module:community` is active for that brand (use `$this->activateModule('community')`), and short-circuits silently when off; a second membership event updates the URLs in place; `identity:replay-profiles` end-to-end — create a user with a membership under an activated brand, run the artisan command, assert both rows exist.
- [ ] **Step 3: Run → FAIL.** Implement models + listeners:

```php
final class ProjectCommunityProfile
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        $attributes = $event instanceof UserProfileUpdated
            ? [
                'username' => $event->username,
                'display_name' => $event->displayName,
                'tagline' => $event->tagline,
                'status_emoji' => $event->statusEmoji,
                'social_links' => $event->socialLinks === [] ? null : $event->socialLinks,
            ]
            : ['username' => $event->username, 'display_name' => $event->displayName];

        CommunityProfile::query()->updateOrCreate(['id' => $event->userId], $attributes);
    }
}

final class ProjectCommunityMember
{
    public function handle(BrandMembershipProfileUpdated $event): void
    {
        $brand = Brand::query()->find($event->brandId);

        // Per-brand gate on the EVENT's brand, not the request's — replays
        // run with no request. A brand that turns Community on later is
        // backfilled by identity:replay-profiles (SPEC "Activation").
        if ($brand === null || ! Feature::for($brand)->active(app(ModuleGate::class)->featureName('community'))) {
            return;
        }

        CommunityMember::withoutBrandScope()->updateOrCreate(
            ['user_id' => $event->userId, 'brand_id' => $event->brandId],
            [
                'avatar_url' => $event->avatarUrl,
                'banner_url' => $event->bannerUrl,
                'member_since' => $event->firstSeenAt,
            ],
        );
    }
}
```
Provider:
```php
protected function registerListeners(): void
{
    Event::listen(UserRegistered::class, ProjectCommunityProfile::class);
    Event::listen(UserProfileUpdated::class, ProjectCommunityProfile::class);
    Event::listen(BrandMembershipProfileUpdated::class, ProjectCommunityMember::class);
}
```
(Explicit `brand_id` in `updateOrCreate` bypasses the ambient-context stamp on purpose; `withoutBrandScope()` because the listener runs off any host.)
- [ ] **Step 4: Run the community tests, then messaging/content/registration (shared events), then full suite.** PASS.
- [ ] **Step 5: Pint + commit** — `git commit -m "Community learns who people are: the profile read models"`.

---

### Task 8: u/{username} — Community's first routed page

**Files:**
- Create: `app-modules/community/src/Http/Controllers/ProfileController.php`
- Create: `app-modules/community/src/Http/Concerns/ResolvesProfiles.php`
- Create: `app-modules/community/resources/js/pages/community/Profile.vue`
- Modify: `app-modules/community/routes/web/community.php`
- Test: `app-modules/community/tests/ProfilePageTest.php`

**Interfaces:**
- Consumes: Task 7 models; `Konventix.Community.Data` TS namespace is NOT needed — pass a plain shaped array.
- Produces: route `GET /u/{username}` name `community.profile.show` (the provider wraps it in `web` + `ensure-module:community` automatically — declare it bare); trait `ResolvesProfiles::resolveProfileOrFail(Request $request, string $username): array{profile: CommunityProfile, member: CommunityMember}` enforcing the full 404 matrix (Task 9 reuses it).

- [ ] **Step 1: Failing tests** — the 404 matrix is the point (every denial is 404, never 403 or a login redirect — membership is personal data, ADR-0051 §7):

```php
// helpers local to this file, prefixed community* (Pest helpers are process-global)
it('renders a member profile for a signed-in fellow member', ...);   // activateModule; project rows via events; assert component 'community/Profile', props username/displayName/memberSince/avatarUrl
it('404s for a guest', ...);                                          // no login redirect!
it('404s for a signed-in non-member of this brand', ...);
it('404s when the subject is not a member of this brand', ...);
it('404s for an unknown username', ...);
it('404s when the community module is off', ...);                     // no activateModule
it('resolves the username case-insensitively', ...);                  // /u/ADA-lovelace finds ada-lovelace
it('omits empty optional fields', ...);                               // tagline null → prop null; page renders nothing for it (prop-level assert suffices)
```
To arrange rows, emit the Identity events (with the community module activated) — never insert read-model rows directly.

- [ ] **Step 2: Run → FAIL.** Implement:

```php
trait ResolvesProfiles
{
    /**
     * The whole 404 matrix in one place (ADR-0051 §7): signed-out, non-member
     * viewer, unknown handle and non-member subject are all the same 404 —
     * membership in a brand is itself personal data, so no answer may differ.
     *
     * @return array{profile: CommunityProfile, member: CommunityMember}
     */
    protected function resolveProfileOrFail(Request $request, string $username): array
    {
        $viewer = $request->user();

        abort_if($viewer === null, 404);

        abort_unless(
            CommunityMember::query()->where('user_id', (string) $viewer->getAuthIdentifier())->exists(),
            404,
        );

        $profile = CommunityProfile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->first();

        abort_if($profile === null, 404);

        $member = CommunityMember::query()->where('user_id', $profile->getKey())->first();

        abort_if($member === null, 404);

        return ['profile' => $profile, 'member' => $member];
    }
}
```
Controller `show(Request $request, string $username)`: resolve, then

```php
return Inertia::render('community/Profile', [
    'profile' => [
        'username' => $profile->username,
        'displayName' => $profile->display_name,
        'tagline' => $profile->tagline,
        'statusEmoji' => $profile->status_emoji,
        'socialLinks' => $profile->social_links ?? (object) [],
        'avatarUrl' => $member->avatar_url,
        'bannerUrl' => $member->banner_url,
        'memberSince' => $member->member_since->toDateString(),
        'isSelf' => $profile->getKey() === (string) $request->user()->getAuthIdentifier(),
    ],
]);
```
Route (bare, in `community.php` below the header comment):
```php
Route::get('/u/{username}', [ProfileController::class, 'show'])
    ->where('username', '[A-Za-z0-9-]{1,64}')
    ->name('community.profile.show');
```

- [ ] **Step 3: The page.** `community/Profile.vue`: banner strip (token background when `bannerUrl` null), avatar (`img` when set, else initials circle via `useInitials`), `displayName` + `statusEmoji` inline, `@username` in `text-text-muted`, "Member since {date}" (`Intl.DateTimeFormat` medium), tagline paragraph only when non-null, social links as a `<ul>` of external `<a target="_blank" rel="noopener noreferrer">` links built by a local helper:

```ts
const socialHref: Record<string, (v: string) => string | null> = {
    steam: (v) => `https://steamcommunity.com/id/${v}`,
    discord: () => null, // no canonical profile URL — render as copyable text
    twitch: (v) => `https://twitch.tv/${v}`,
    youtube: (v) => `https://youtube.com/@${v.replace(/^@/, '')}`,
    x: (v) => `https://x.com/${v}`,
    instagram: (v) => `https://instagram.com/${v}`,
    website: (v) => v,
};
```
Render only present keys; empty map renders no section ("filling a field is choosing to show it"). Semantic tokens only; single root element.
- [ ] **Step 4: `npm run build`, run the test file, community suite.** PASS.
- [ ] **Step 5: Pint + commit** — `git commit -m "A page for the person: u/{username} on the brand host"`.

---


**Amendment (2026-09-01, user-directed — ADR-0052 profile visibility):** the 404 matrix above is superseded by the visibility-aware gate. `ResolvesProfiles::resolveProfileOrFail` becomes: (1) resolve profile by folded username, 404 if none; (2) resolve the subject's `CommunityMember` row for the current brand, 404 if none — the subject must be a member of this brand under EVERY visibility; (3) switch on `$profile->visibility`: `public` → no viewer checks at all (guests render the page); `internal` → viewer signed in AND has a CommunityMember row on this brand, else 404; `event` → the internal checks PLUS at least one shared confirmed event participation: `CommunityEventParticipation` rows (brand-scoped) where viewer and subject both have `confirmed = true` for the same `event_id`, else 404. All denials stay 404. Comments are internal-scope regardless of visibility: the `comments`/`canComment` props are computed only when the viewer passes the `internal` checks; a guest on a public profile gets `comments: null` and the page renders no comments section. Test matrix additions: public profile renders for a guest; public profile hides comments from a guest; event-only 404s for a member with no shared confirmed event; event-only renders when a shared confirmed participation exists on this brand; internal behaves as the original matrix. Consumes from Task 13: `CommunityProfile::$visibility`, `CommunityEventParticipation` (fields `id` = registrationId, `user_id`, `event_id`, `confirmed`, brand-scoped), and `Konventix\Identity\Enums\ProfileVisibility`.

---

### Task 9: Profile-wall comments and reactions

**Files:**
- Create: `app-modules/community/database/migrations/2026_09_01_153000_create_community_comments_and_reactions.php`
- Create: `app-modules/community/src/Models/CommunityComment.php`, `app-modules/community/src/Models/CommunityReaction.php`
- Create: `app-modules/community/src/Http/Controllers/ProfileCommentController.php`
- Create: `app-modules/community/database/factories/CommunityCommentFactory.php`
- Modify: `app-modules/community/routes/web/community.php`, `app-modules/community/src/Http/Controllers/ProfileController.php`, `app-modules/community/resources/js/pages/community/Profile.vue`
- Test: `app-modules/community/tests/ProfileCommentsTest.php`

**Interfaces:**
- Consumes: Task 8's `ResolvesProfiles`.
- Produces: `community_comments` (BelongsToBrand; subject triple `module`/`entity_type`/`entity_id` — today always `('community', 'profile', <profile user ulid>)`; `author_user_id` ulid; `body` text; `reaction_count` unsigned default 0; slice 26 reuses the triple for gallery items) and `community_reactions` (clone of `content_reactions`: `user_id`, triple pointing at `('community', 'comment', <comment ulid>)`, `UPDATED_AT = null`, unique `(user_id, module, entity_type, entity_id)`). Model constants: `CommunityComment::MODULE = 'community'`, `::ENTITY_PROFILE = 'profile'`; `CommunityReaction::ENTITY_COMMENT = 'comment'`; `CommunityReaction::countFor(CommunityComment $comment): int` (withoutBrandScope + brand filter, `ContentReaction::countFor` precedent). Routes (bare — NOT in an `auth` group: the resolver answers 404 for guests, a login redirect would concede the page exists): `POST /u/{username}/comments` (`community.profile.comments.store`), `DELETE /u/{username}/comments/{comment}` (`.destroy`), `POST /u/{username}/comments/{comment}/react` (`.react`), `DELETE /u/{username}/comments/{comment}/react` (`.unreact`).

- [ ] **Step 1: Migration** (comments: `foreignUlid('brand_id')->constrained()->cascadeOnDelete()`, `string('module', 32)`, `string('entity_type', 32)`, `ulid('entity_id')`, `ulid('author_user_id')->index()`, `text('body')`, `unsignedInteger('reaction_count')->default(0)`, timestamps, `index(['module', 'entity_type', 'entity_id'])`; reactions exactly like `content_reactions` incl. `timestamp('created_at')->useCurrent()` and the unique quadruple). Factory for comments only (used by delete/react tests): `author_user_id => User::factory()`, `module`/`entity_type` constants, `entity_id => (string) Str::ulid()`, `body => fake()->sentence()`.
- [ ] **Step 2: Failing tests** (all under `activateModule('community')`, rows arranged via events + factory):

```php
it('lets a member comment on a profile', ...);          // POST body → row with subject ('community','profile',$subjectId); page then shows it
it('404s a comment from a non-member', ...);
it('caps the body at 2000 characters', ...);            // assertInvalid(['body'])
it('lets the author delete their comment', ...);
it('lets the wall owner delete any comment on their wall', ...);
it('403s a third party deleting someone else\'s comment', ...);  // existence already conceded by the 200 page — ownership is 403 (MyRegistrations precedent)
it('reacts once and recounts', ...);                    // react twice → count 1
it('cannot react to an own comment', ...);              // ValidationException → assertInvalid
it('unreacts and recounts', ...);
```

- [ ] **Step 3: Run → FAIL.** Implement the controller (uses `ResolvesProfiles` for every method — the same 404 wall guards writes; then Content's transaction/recount pattern):

```php
public function store(Request $request, string $username): RedirectResponse
{
    ['profile' => $profile] = $this->resolveProfileOrFail($request, $username);

    $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

    CommunityComment::query()->create([
        'module' => CommunityComment::MODULE,
        'entity_type' => CommunityComment::ENTITY_PROFILE,
        'entity_id' => $profile->getKey(),
        'author_user_id' => (string) $request->user()->getAuthIdentifier(),
        'body' => $validated['body'],
    ]);

    return back();
}
```
`destroy`: resolve, find the comment scoped to this profile's subject or 404, `abort_unless($isAuthor || $isWallOwner, 403)`, delete (reactions on it: `CommunityReaction::query()->forSubject(...)->delete()` first). `react`/`unreact`: guard own comment via `ValidationException`, `firstOrCreate`/`delete` + `forceFill(['reaction_count' => CommunityReaction::countFor($comment)])->save()` inside `DB::transaction` (Content's `ReactToComment` pattern, minus the contract event — ruling 6).
- [ ] **Step 4: `ProfileController::show`** adds `comments` (approved-equivalent: all, ordered by `created_at`; map to `{id, body, authorName, authorUsername, postedAt, isMine, canDelete, reactionCount, hasReacted}` — author names from `CommunityProfile::query()->whereKey($authorIds)->get()` in one lookup, reacted set in one `whereIn` query, Content's `show()` precedent) and `canComment` (always true once resolved — the resolver is the gate). **Page**: comment list + composer (`useForm({ body: '' })`, `preserveScroll`, reset on success), heart button copied from Content's Show.vue (aria-pressed, aria-label, no reaction on own comments), delete button (icon-only + `aria-label`) when `canDelete`.
- [ ] **Step 5: Build, test file green, community suite green, pint, commit** — `git commit -m "A wall to write on: profile comments and reactions"`.

---

### Task 10: GDPR follows the shapes

**Files:**
- Create: `app-modules/community/src/Gdpr/CommunityGdprHandler.php`
- Modify: `app-modules/community/src/Providers/CommunityServiceProvider.php` (add `registerGdprExporters`)
- Modify: `app-modules/identity/src/Gdpr/IdentityGdprHandler.php`
- Test: `app-modules/community/tests/CommunityGdprTest.php`, extend `app-modules/identity/tests/` GDPR coverage (the file that tests `IdentityGdprHandler` — extend `SocialIdentityTest.php`'s handler tests or the compliance sweep tests as fits), check `app-modules/compliance/tests/{GdprSweepTest,OperatorWideFanOutTest}.php`

**Interfaces:**
- Consumes: `Konventix\Compliance\Gdpr\{GdprHandler, SubjectRef, GdprHandlerRegistry}`, `Konventix\Compliance\Enums\DeletionMode` (exact contract: `module(): string`, `exportFor(SubjectRef $subject): array`, `delete(SubjectRef $subject, DeletionMode $mode): void`; email-only subjects → no-op, Community holds nothing keyed by bare address).
- Produces: registered `community` handler.

- [ ] **Step 1: Failing tests.**
  - Identity: after `delete()`, `username` is `deleted-<lowercased ulid>` and `tagline`/`status_emoji`/`social_links` are null; `exportFor` includes the four new account fields and each membership's `avatar_media_id`/`banner_media_id`; deleting a user whose membership carries an avatar releases the media usage (assert the `media_usages` row is gone — `MediaUsage::withoutBrandScope()`).
  - Community: `exportFor` returns profile row, member rows (brand_id + member_since + urls), comments authored, reaction subjects; `delete()` removes reactions (and recounts the comments they sat on), removes authored comments, removes member rows and the profile row; idempotent on second call; email-only `SubjectRef` no-ops both ways.
- [ ] **Step 2: Run → FAIL.** Implement.
  - `IdentityGdprHandler::delete()`: before the membership delete, loop `BrandMembership::withoutBrandScope()->where('user_id', $subjectId)->get()` and `releaseUsage` each non-null `avatar_media_id`/`banner_media_id` with triple `('identity', 'membership', $membership->getKey())` (inject `MediaLibrary` via constructor — note the handler is registered with `new IdentityGdprHandler` in the provider; switch that line to `$this->app->make(IdentityGdprHandler::class)` and fix `SocialIdentityTest`'s direct `new IdentityGdprHandler` constructions to `app(...)`). Tombstone block gains:

```php
'username' => 'deleted-'.strtolower($user->getKey()),
'tagline' => null,
'status_emoji' => null,
'social_links' => null,
```
  - `exportFor`: `account` gains `username`, `tagline`, `status_emoji`, `social_links`; the memberships map gains `'avatar_media_id' => $membership->avatar_media_id, 'banner_media_id' => $membership->banner_media_id`.
  - `CommunityGdprHandler`: `module()` = `'community'`; both methods return early on `! $subject->isAccountBacked()`; export/delete via `withoutBrandScope()` on the brand-scoped models. On delete, recount before removing the user's reactions is wrong — remove reactions, then recount each affected comment that still exists.
  - Provider: `registerGdprExporters()` → `$this->app->make(GdprHandlerRegistry::class)->register($this->app->make(CommunityGdprHandler::class));` (match Identity's provider hook name exactly — copy its signature).
- [ ] **Step 3: Run community + identity + full compliance suite** — if `GdprSweepTest`/`OperatorWideFanOutTest` assert module sets or archive keys, update expectations honestly (a new `community` key with content only when rows exist). PASS.
- [ ] **Step 4: Pint + commit** — `git commit -m "GDPR follows the profile shapes"`.

---

### Task 11: Documentation truth pass

**Files:**
- Modify: `app-modules/community/SPEC.md`, `app-modules/community/TODO.md`, `app-modules/identity/SPEC.md` (if it lists the shared-prop promise as open), `app-modules/identity/TODO.md`, `docs/ROADMAP.md`

**Interfaces:** none — prose only. No code changes in this task.

- [ ] **Step 1: `community/SPEC.md`** — graduate the profile read model section from provisional to settled: describe `community_profiles` + `community_members`, the three consumed events by exact name, the per-brand gate on the member projection, and the backfill answer ("enable the feature, then run `identity:replay-profiles`"); the header keeps chat/presence/achievements as Phase 3. Consumes table row for Identity events changes from "(proposed)" to settled. Add the comments/reactions subject-triple tables to "Owns".
- [ ] **Step 2: `community/TODO.md`** — replace "Nothing scheduled" with the real state: shipped (profile page, comments), next (groups/clans — ROADMAP), open items (no moderation/report flow on profile walls — ruling 6; no "My profile" navigation entry yet; no contract events for community comments).
- [ ] **Step 3: `identity` docs** — SPEC: mark the four-field prop as delivered; TODO: strike anything slice 25 closed (check for a username/profile entry).
- [ ] **Step 4: `docs/ROADMAP.md`** — append `**Done**` (with the one-line parenthetical convention used by slices 22/24) to slice 25; update P1's note ("payload widened with slice 25's fields" → done).
- [ ] **Step 5: Commit** — `git commit -m "The docs say what slice 25 settled"`.

---

## Verification

Full suite in the container (SSR running), `vendor/bin/pint --dirty`, `npm run build` clean, `npx eslint` is known-broken on this host (root-litter dirs) — rely on CI for eslint. The final whole-branch review gates the merge.

---

### Task 12: Container logs are well-formatted JSON

Added mid-run at the user's request (2026-09-01) — deploy-facing, independent of the profile work.

**Files:**
- Modify: `config/logging.php` (only if the `stderr` channel lacks a `formatter`/`formatter_with` path — Laravel's default already reads `LOG_STDERR_FORMATTER`; verify and keep the delta minimal)
- Modify: `.env.example` (document `LOG_CHANNEL=stderr` + `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` as the container defaults)
- Test: `tests/Feature/StructuredLogsTest.php`

**Interfaces:**
- Produces: with `LOG_CHANNEL=stderr` and `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, every log record — including a logged exception — leaves the container as one single-line JSON object on stderr. The konventix-helm ConfigMap ships those two env defaults (chart-side change, done in the chart repo with the v0.3.0 bump, not in this task).

- [ ] **Step 1: Failing test** — with config `logging.default=stderr` and `logging.channels.stderr.formatter=Monolog\Formatter\JsonFormatter` set via `Config::set`, resolve the channel and assert its Monolog handler's formatter is a `JsonFormatter`; second case: format a `LogRecord` carrying an exception through that formatter and assert the output is a single line (`str_contains($line, "\n") === false` after rtrim) that `json_decode`s to an array with `message`, `level_name`, `context.exception`.
- [ ] **Step 2: Run → FAIL (or pass immediately if Laravel's default config already wires it — then the test is the regression pin and the only code change is `.env.example`).**
- [ ] **Step 3: Implement the minimal delta** `config/logging.php` needs, if any.
- [ ] **Step 4: Run the test file + full suite (per the simplified policy: this file + smoke; CI covers the rest).**
- [ ] **Step 5: Commit** — `git commit -m "Container logs leave as single-line JSON"`.


---

### Task 13: A profile chooses its audience — visibility (ADR-0052)

Added mid-run at the user's request. MUST run before the amended Task 8.

**Files:**
- Create: `docs/decisions/0052-a-profile-chooses-its-audience.md`
- Create: `app-modules/identity/src/Enums/ProfileVisibility.php`
- Create: `app-modules/identity/database/migrations/2026_09_01_170000_add_profile_visibility_to_users_table.php`
- Create: `app-modules/community/database/migrations/2026_09_01_171000_add_visibility_and_event_participations.php`
- Create: `app-modules/community/src/Models/CommunityEventParticipation.php`, `app-modules/community/src/Listeners/ProjectEventParticipation.php`
- Modify: `app-modules/identity/src/Models/User.php`, `app-modules/identity/src/Events/UserProfileUpdated.php` + every dispatch site (grep sweep as in Task 2), `app-modules/identity/src/Data/ProfileUpdateData.php`, `app-modules/identity/src/Http/Controllers/ProfileController.php`, `resources/js/pages/settings/Profile.vue`, `app-modules/community/src/Models/CommunityProfile.php`, `app-modules/community/src/Listeners/ProjectCommunityProfile.php`, `app-modules/community/src/Providers/CommunityServiceProvider.php`, `app-modules/identity/src/Gdpr/IdentityGdprHandler.php`
- Test: `app-modules/identity/tests/ProfileVisibilityTest.php`, `app-modules/community/tests/EventParticipationProjectionTest.php`

**Interfaces:**
- Produces: `ProfileVisibility` string enum `Event = 'event'`, `Internal = 'internal'`, `Public = 'public'` with `label()` unnecessary (frontend copies inline). `users.profile_visibility` string default `'internal'`, cast to the enum. `UserProfileUpdated` gains required `public string $visibility` (after `socialLinks`). `community_profiles.visibility` string default `'internal'` (plain string in the read model). `community_event_participations`: `id` ULID PK = the REGISTRATION id from the event (no HasUlids), `brand_id` (BelongsToBrand, written via forceFill exactly like CommunityMember — never mass-assignable), `user_id` ulid indexed no FK, `event_id` ulid indexed no FK (ADR-0014), `confirmed` boolean default false, timestamps, index `(user_id, event_id)`. `ProjectEventParticipation` handles `RegistrationSubmitted|RegistrationConfirmed|RegistrationCancelled|RegistrationRejected`.

- [ ] **Step 1: Write ADR-0052** (short, amends ADR-0051 §7): a per-account `profile_visibility` on the profile-of-record — `internal` (default; ADR-0051's members-only rule unchanged), `public` (the owner's explicit opt-in publishes the page to anyone including guests — the "membership is personal data" 404 is the default, not a prohibition on self-disclosure), `event` (viewer must share at least one CONFIRMED event registration with the owner on this brand — sourced from Registration's contract events via a Community read model, since Community may not read Registration models per ADR-0004). Comments stay internal-scope under every visibility. Participation is projected going forward only (Registration has no replay command yet — a brand enabling Community late starts with empty participation; note it). Status: accepted.
- [ ] **Step 2: Failing tests.** Identity: settings PATCH persists `visibility` and the widened event carries it; invalid value rejected (`Rule::enum(ProfileVisibility::class)`); default is internal on a fresh user; anonymization resets visibility to internal. Community: `RegistrationSubmitted` creates an unconfirmed participation row keyed by registrationId (module gate on the EVENT's brand, same Feature::for pattern); `RegistrationConfirmed` flips `confirmed` true by registrationId; `RegistrationCancelled` and `RegistrationRejected` delete the row; module-off brand projects nothing; `UserProfileUpdated` projects `visibility` into community_profiles.
- [ ] **Step 3: Implement.** Migration users: `$table->string('profile_visibility')->default('internal')->after('social_links');`. Enum + `#[Fillable]` + cast + `@property`. Event widening: required param, all dispatch sites pass `$user->profile_visibility->value` (or `visibility:` from the enum's value — event payloads carry scalars), tests fixed via grep sweep. `ProfileUpdateData`: `public readonly string $visibility` with `['required', Rule::enum(ProfileVisibility::class)]`. Settings form: a three-option radio group (semantic tokens; labels "Public — anyone, even without an account", "Internal — signed-in members of this brand", "Event only — people confirmed for the same event") with helper copy; profile prop gains `visibility`. `ProjectCommunityProfile` writes visibility (Registered branch: `'internal'`). `ProjectEventParticipation` per the CommunityMember forceFill pattern; registered explicitly for all four Registration events. `RegistrationRejected`'s payload must be checked before use (it may carry only registrationId — that suffices for delete). `IdentityGdprHandler`: export gains `profile_visibility`; anonymize tombstone sets `'profile_visibility' => ProfileVisibility::Internal`.
- [ ] **Step 4: Scoped suites (identity, community, messaging, content, registration), pint, scoped phpstan, `npm run format`, build.**
- [ ] **Step 5: Commit** — `git commit -m "A profile chooses its audience"`.

---

### Task 14: Status-emoji picker

Added mid-run at the user's request (dependency approved by the user).

**Files:**
- Modify: `package.json` (+ lockfile) — add `vue3-emoji-picker` (pin exact version)
- Modify: `resources/js/pages/settings/Profile.vue`
- Test: build + existing ProfileOfRecordTest stays green (server contract unchanged)

**Interfaces:** consumes the existing `statusEmoji` input; server-side validation unchanged (single emoji grapheme).

- [ ] **Step 1:** `npm install vue3-emoji-picker` (exact pin). Import its css in the component scope only.
- [ ] **Step 2:** Replace the bare statusEmoji text input with: a button (labelled with the current emoji or a placeholder, `aria-label="Choose status emoji"`, `aria-expanded`) toggling a popover hosting `<EmojiPicker :native="true" @select="..." />`; select writes the emoji into the (still present, now readonly) input + a visible "clear" button (`aria-label="Clear status emoji"`). Keyboard: Escape closes, focus returns to the trigger. Popover styled with semantic tokens; the picker's own theme prop set to follow dark mode if supported, else accept its default.
- [ ] **Step 3:** `npm run format`, `npm run build` + `build:ssr` (picker must be SSR-safe — if it breaks SSR, lazy-load it client-only via `defineAsyncComponent` guarded by `typeof window !== 'undefined'`), `npx vue-tsc --noEmit`.
- [ ] **Step 4:** Run tests/Feature/Settings + identity ProfileOfRecordTest (server unchanged, must stay green).
- [ ] **Step 5: Commit** — `git commit -m "Pick the status emoji instead of typing it"`.
