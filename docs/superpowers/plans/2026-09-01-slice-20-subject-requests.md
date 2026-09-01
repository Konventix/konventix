# Slice 20 — Subject requests span the operator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a data-subject request span the operator rather than a brand, let a person with no account file one from a public page, and make consent withdrawal follow the policy's declared lawful basis.

**Architecture:** The `GdprHandler` contract stops taking a brand and starts taking a `SubjectRef` (a ULID *or* an email). Every handler drops its `->where('brand_id', $brandId)` filter, which is what turns N brand-scoped archives into one operator-wide archive. A public page writes a short-lived verification row holding a stranger's address and creates no request until the mailed link is followed. `PolicyVersion` gains a `lawful_basis` column from which self-service-versus-request withdrawal is derived, so Art. 7(3) cannot be switched off by configuration.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 4, PostgreSQL 18, Inertia v3 + Vue 3 `<script setup lang="ts">`, Tailwind v4, spatie/laravel-data, Wayfinder.

**Spec:** `docs/decisions/0050-subject-requests-span-the-operator.md` (ADR-0050). Read it before Task 1; every task below argues from one of its ten numbered decisions and names which.

## Global Constraints

- **Tests run inside the container:** `docker compose exec -T laravel.test php artisan test --compact --filter=X`. From the host `DB_HOST=pgsql` does not resolve. Never run two test processes at once — they share one `testing` database and drop each other's tables.
- **`Event::fake()` MUST be called with explicit event classes.** Bare `Event::fake()` swallows `eloquent.*` events, so `BelongsToBrand` never stamps `brand_id` and inserts die on the not-null constraint.
- **Pest helper functions cannot call protected `TestCase` methods.** Call `$this->withBrand()` inside the test closure and pass the model in. Helper function names are process-global across all module test files — prefix them uniquely.
- **Never write `where('brand_id', ...)` as a scoping mechanism** (ADR-0010). In a GDPR handler, `withoutBrandScope()` with no brand filter is correct and deliberate — that is what operator-wide means.
- **Never `use` another module's Eloquent model** (ADR-0004). Cross-module data travels as IDs and scalars through events.
- **Migrations are expand/contract** (ADR-0049 §9): new columns nullable or defaulted; a column stops being read one release before it is dropped.
- **Every module registers its own wiring from its own ServiceProvider** (ADR-0003). Never add module wiring to a central provider.
- **Run before every commit:** `docker compose exec -T laravel.test vendor/bin/pint --dirty` then `docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G`.
- **A new Vue page needs `npm run build`** or the feature test 500s on a missing Vite manifest entry.
- Locale strings ship `de` and `en`; `de` is `defaultLocale` for every Compliance template.

---

## File Structure

**New — Compliance:**
- `src/Gdpr/SubjectRef.php` — the value object: a ULID or an email, exactly one.
- `src/Models/DataSubjectRequestVerification.php` — the pre-request row holding an unverified address.
- `src/Http/Controllers/PublicDataRequestController.php` — form, submit, verify.
- `src/Console/SweepUnverifiedRequestsCommand.php` — the 24-hour sweep.
- `src/Enums/LawfulBasis.php` — `consent | contract | legitimate_interest`.
- `src/Events/ConsentWithdrawalRequested.php`
- `src/Data/RequestVerificationMailData.php`
- `resources/js/pages/privacy/RequestData.vue` — the public form.
- `database/factories/DataSubjectRequestVerificationFactory.php`
- 3 migrations, 5 test files.

**Modified — Compliance:** `src/Gdpr/GdprHandler.php`, `ComplianceGdprHandler.php`, `src/Jobs/{AssembleSubjectExport,RunSubjectDeletion}.php`, `src/Listeners/{DispatchSubjectRequestFanOut,NotifySubjectOfRequestOutcome}.php`, `src/Actions/{OpenSubjectRequest,WithdrawConsent}.php`, `src/Models/{DataSubjectRequest,PolicyVersion}.php`, `src/Enums/DataSubjectRequestType.php`, `src/Http/Controllers/{DataRequestController,PrivacyController}.php`, `src/Providers/ComplianceServiceProvider.php`, `routes/web/compliance.php`.

**Modified — eight other modules:** one `*GdprHandler.php` each in `admission`, `commerce`, `competition`, `content`, `identity`, `messaging`, `registration`, `staffing`.

**Why Task 2 is one large task and cannot be split:** PHP interfaces have no default methods. The moment `GdprHandler::exportFor` changes signature, all nine implementations are fatal until updated. There is no intermediate green state, so the contract and every implementation move in one commit.

---

### Task 1: `SubjectRef` — a subject is a ULID or an email

Implements ADR-0050 decision 3. Nothing else depends on ordering; this is the foundation Task 2 consumes.

**Files:**
- Create: `app-modules/compliance/src/Gdpr/SubjectRef.php`
- Test: `app-modules/compliance/tests/SubjectRefTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Konventix\Compliance\Gdpr\SubjectRef` with `public readonly ?string $userId`, `public readonly ?string $email`; static `forUser(string $userId): self`, `forEmail(string $email): self`; instance `isAccountBacked(): bool`, `key(): string`. Constructor is private — construction goes through the named constructors so the invariant cannot be bypassed.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Gdpr\SubjectRef;

it('carries a user id and reports itself account-backed', function (): void {
    $ulid = (string) Str::ulid();
    $subject = SubjectRef::forUser($ulid);

    expect($subject->userId)->toBe($ulid)
        ->and($subject->email)->toBeNull()
        ->and($subject->isAccountBacked())->toBeTrue()
        ->and($subject->key())->toBe($ulid);
});

it('carries an email and reports itself not account-backed', function (): void {
    $subject = SubjectRef::forEmail('Someone@Example.DE');

    expect($subject->userId)->toBeNull()
        // Lower-cased on the way in: the address is a lookup key against
        // messaging_suppressions.email, and a case-varied duplicate would
        // silently miss the suppression that already exists.
        ->and($subject->email)->toBe('someone@example.de')
        ->and($subject->isAccountBacked())->toBeFalse()
        ->and($subject->key())->toBe('someone@example.de');
});

it('refuses an empty user id', function (): void {
    SubjectRef::forUser('');
})->throws(InvalidArgumentException::class);

it('refuses an empty email', function (): void {
    SubjectRef::forEmail('  ');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=SubjectRefTest`
Expected: FAIL — `Class "Konventix\Compliance\Gdpr\SubjectRef" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

use InvalidArgumentException;

/**
 * Who a subject request is about (ADR-0050 §3).
 *
 * Exactly one of the two is set. An account-backed subject is a ULID, and
 * every handler can answer for it. An email-only subject is someone we
 * never gave an account — the address is all we have, and only the modules
 * that key data on an address (Messaging's suppressions and delivery log)
 * can say anything at all. A handler that cannot answer for a bare address
 * returns nothing rather than guessing; that is honest, not lossy.
 *
 * The private constructor is the invariant: there is no way to build one
 * with both fields set, or neither.
 */
final readonly class SubjectRef
{
    private function __construct(
        public ?string $userId,
        public ?string $email,
    ) {}

    public static function forUser(string $userId): self
    {
        if (trim($userId) === '') {
            throw new InvalidArgumentException('A subject reference needs a user id or an email.');
        }

        return new self(userId: $userId, email: null);
    }

    public static function forEmail(string $email): self
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === '') {
            throw new InvalidArgumentException('A subject reference needs a user id or an email.');
        }

        return new self(userId: null, email: $normalized);
    }

    public function isAccountBacked(): bool
    {
        return $this->userId !== null;
    }

    /**
     * A stable identifier for logs and archive filenames. Never rendered to
     * another subject — an email here is still personal data.
     */
    public function key(): string
    {
        return $this->userId ?? (string) $this->email;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=SubjectRefTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add app-modules/compliance/src/Gdpr/SubjectRef.php app-modules/compliance/tests/SubjectRefTest.php
git commit -m "A subject is a person, not a person at a brand"
```

---

### Task 2: The fan-out contract stops taking a brand

Implements ADR-0050 decisions 1 (partly) and 2. **Atomic by necessity** — see File Structure. The mechanical shape is identical in eight handlers: delete the `->where('brand_id', $brandId)` line, take `SubjectRef`, and early-return for an email-only subject.

**Files:**
- Modify: `app-modules/compliance/src/Gdpr/GdprHandler.php`
- Modify: `app-modules/compliance/src/Gdpr/ComplianceGdprHandler.php`
- Modify: `app-modules/{admission,commerce,competition,content,identity,messaging,registration,staffing}/src/Gdpr/*GdprHandler.php`
- Modify: `app-modules/compliance/src/Jobs/AssembleSubjectExport.php`, `RunSubjectDeletion.php`
- Test: `app-modules/compliance/tests/OperatorWideFanOutTest.php` (create)

**Interfaces:**
- Consumes: `SubjectRef::forUser()`, `SubjectRef::forEmail()`, `isAccountBacked()`, `key()` from Task 1.
- Produces: `GdprHandler::exportFor(SubjectRef $subject): array` and `GdprHandler::delete(SubjectRef $subject, DeletionMode $mode): void`. `AssembleSubjectExport` and `RunSubjectDeletion` keep their `(string $requestId, string $brandId)` constructors in this task — Task 3 removes `$brandId` once the column is gone. The archive path becomes `subject-exports/{requestId}.json`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Jobs\AssembleSubjectExport;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;

it('exports one archive covering every brand the person belongs to', function (): void {
    // Two brands under one operator; one person a member of both. Before
    // ADR-0050 this produced two archives with one membership each.
    $first = $this->withBrand();
    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->getKey(), 'brand_id' => $first->getKey()]);

    $second = $this->withBrand();
    BrandMembership::factory()->create(['user_id' => $user->getKey(), 'brand_id' => $second->getKey()]);

    Storage::fake('exports');

    $request = DataSubjectRequest::create([
        'subject_id' => $user->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
    ]);

    (new AssembleSubjectExport($request->getKey(), $second->getKey()))
        ->handle(app(GdprHandlerRegistry::class), app(\Konventix\Compliance\Actions\RecordModuleResponse::class));

    $archive = json_decode(Storage::disk('exports')->get("subject-exports/{$request->getKey()}.json"), true);

    expect($archive['modules']['identity']['brand_memberships'])->toHaveCount(2);
});

it('lets a handler answer for an email-only subject and no-ops the rest', function (): void {
    $this->withBrand();

    $registry = app(GdprHandlerRegistry::class);
    $subject = SubjectRef::forEmail('nobody@example.de');

    // Identity has no account for a bare address, so it contributes nothing
    // rather than throwing — the contract's "a subject with no data is a
    // no-op, not an error" rule now covers a subject with no account.
    expect($registry->all()['identity']->exportFor($subject))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=OperatorWideFanOutTest`
Expected: FAIL — `exportFor()` expects 2 arguments, and the archive path does not exist.

- [ ] **Step 3a: Change the contract**

In `app-modules/compliance/src/Gdpr/GdprHandler.php`, replace both signatures and update the docblock:

```php
    /**
     * This module's scalar contribution to a subject's export archive.
     * Calling it twice for the same subject must yield the same shape.
     *
     * The subject is a person, not a person-at-a-brand (ADR-0050 §2): query
     * with `withoutBrandScope()` and no brand filter. A handler that cannot
     * answer for an email-only subject returns an empty array — that is a
     * no-op, not an error.
     *
     * @return array<string, mixed>
     */
    public function exportFor(SubjectRef $subject): array;

    /**
     * Anonymize or erase this module's rows for the subject, across every
     * brand of the operator. Must be safe to call more than once.
     */
    public function delete(SubjectRef $subject, DeletionMode $mode): void;
```

Add `use Konventix\Compliance\Gdpr\SubjectRef;` where needed (same namespace — no import required inside `Gdpr/`).

- [ ] **Step 3b: Update the eight mechanical handlers**

For each of `admission`, `commerce`, `competition`, `content`, `messaging`, `registration`, `staffing`, and Compliance's own: change both signatures, add the email guard at the top of each method, and **delete every `->where('brand_id', $brandId)` line**. `withoutBrandScope()` stays. Example — `CompetitionGdprHandler`:

```php
    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        return [
            'team_memberships' => CompetitionTeamMember::withoutBrandScope()
                ->where('user_id', $subjectId)
                // ... unchanged
        ];
    }
```

`MessagingGdprHandler` is the one that gains a real email branch rather than an early return — its suppressions and delivery log are keyed on the address:

```php
    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [
                // Keyed on the address, and deliberately outliving any
                // account: a suppression exists because that address said no.
                'suppressions' => Suppression::withoutBrandScope()
                    ->where('email', $subject->email)
                    ->get()
                    ->map(fn (Suppression $s): array => [
                        'channel' => $s->channel->value,
                        'reason' => $s->reason,
                        'lifted_at' => $s->lifted_at?->toIso8601String(),
                    ])
                    ->all(),
                'messages' => MessageRecord::withoutBrandScope()
                    ->where('recipient_email', $subject->email)
                    ->orderBy('queued_at')
                    ->get()
                    ->map(fn (MessageRecord $r): array => [
                        'template' => $r->template_key,
                        'channel' => $r->channel->value,
                        'status' => $r->status->value,
                        'queued_at' => $r->queued_at->toIso8601String(),
                    ])
                    ->all(),
            ];
        }

        $subjectId = $subject->userId;
        // ... existing body, minus every ->where('brand_id', $brandId)
    }
```

Its `delete()` gains the matching branch: scrub `recipient_email` on the delivery log for that address, and **leave the suppression row's `email` intact** — the existing docblock's rule ("forgetting that would start sending again to an address that already said no") is unchanged by ADR-0050.

- [ ] **Step 3c: Simplify `IdentityGdprHandler` — the last-membership rule goes away**

Its docblock says the rule "stops being needed the day requests are opened operator-wide". That day is this task. Replace the guarded body:

```php
    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $subjectId = $subject->userId;

        // Every membership, not one brand's: the request spans the operator
        // (ADR-0050 §1), so a person leaving takes all of it with them. The
        // old "delete this brand's membership, anonymize only if it was the
        // last" rule existed because the fan-out arrived per brand; it no
        // longer does.
        BrandMembership::withoutBrandScope()->where('user_id', $subjectId)->delete();

        /** @var User|null $user */
        $user = User::query()->find($subjectId);

        if ($user === null || $user->state === AccountState::Anonymized) {
            return;
        }

        // ... the rest of the existing body unchanged
    }
```

Delete the paragraph in the class docblock describing the per-brand rule and replace it with a sentence naming ADR-0050.

- [ ] **Step 3d: Update the two jobs**

In `AssembleSubjectExport::handle()`:

```php
        $subject = SubjectRef::forUser($request->subject_id);

        foreach ($registry->all() as $module => $handler) {
            /** @var GdprHandler $handler */
            try {
                $contributions[$module] = $handler->exportFor($subject);
                $recordModuleResponse($request, $module, ModuleResponseState::Completed);
            } catch (Throwable $exception) {
                // ... unchanged
            }
        }
```

and the archive path stops being brand-keyed:

```php
        // No brand in the path: one archive per request, for the whole
        // operator (ADR-0050 §1). `archive_disk` is sealed on the row, so
        // archives written under the old {brandId}/ path stay readable.
        $path = sprintf('subject-exports/%s.json', $request->getKey());
```

Remove `'brand_id' => $this->brandId` from the archive JSON body. In `RunSubjectDeletion::handle()`, make the same `SubjectRef::forUser(...)` substitution for `$handler->delete($subject, $mode)`.

- [ ] **Step 4: Run the full Compliance and per-module suites**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=Gdpr`
Then: `docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS, including the two new cases. Existing tests that asserted per-brand isolation of an archive will fail — that is the behaviour ADR-0050 reverses. Update those assertions to expect one operator-wide archive; do **not** re-scope the handler to make an old assertion pass.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add -A app-modules/*/src/Gdpr app-modules/compliance/src/Jobs app-modules/compliance/tests
git commit -m "One person, one archive: the fan-out stops asking which brand"
```

---

### Task 3: The request table stops being brand-scoped

Implements ADR-0050 decisions 1 and 3 at the schema level. Expand/contract: `filed_on_brand_id` is added and populated while `brand_id` is still present; the drop is a later release.

**Files:**
- Create: `app-modules/compliance/database/migrations/2026_09_01_100000_widen_data_subject_requests_for_operator_scope.php`
- Modify: `app-modules/compliance/src/Models/DataSubjectRequest.php`
- Modify: `app-modules/compliance/src/Actions/OpenSubjectRequest.php`
- Modify: `app-modules/compliance/database/factories/DataSubjectRequestFactory.php`
- Test: `app-modules/compliance/tests/SubjectRequestScopeTest.php` (create)

**Interfaces:**
- Consumes: `SubjectRef` from Task 1.
- Produces: `DataSubjectRequest` without `BelongsToBrand`, with nullable `subject_id`, new `subject_email` and `filed_on_brand_id`. `OpenSubjectRequest::__invoke(SubjectRef $subject, DataSubjectRequestType $type, ?DeletionMode $mode = null, ?string $filedOnBrandId = null): DataSubjectRequest` — signature change from `(string $subjectId, ...)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;

it('finds a request filed on one brand while another brand is resolved', function (): void {
    $first = $this->withBrand();

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forEmail('someone@example.de'),
        DataSubjectRequestType::Export,
        null,
        $first->getKey(),
    );

    // Resolve a different brand, then look the request up with no
    // withoutBrandScope() escape: it is not brand-scoped any more.
    $this->withBrand();

    expect(DataSubjectRequest::query()->find($request->getKey()))->not->toBeNull()
        ->and($request->filed_on_brand_id)->toBe($first->getKey())
        ->and($request->subject_email)->toBe('someone@example.de')
        ->and($request->subject_id)->toBeNull();
});

it('refuses a request with neither a subject id nor an email', function (): void {
    $this->withBrand();

    DataSubjectRequest::create([
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
    ]);
})->throws(Illuminate\Database\QueryException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=SubjectRequestScopeTest`
Expected: FAIL — unknown column `subject_email`.

- [ ] **Step 3: Write the migration and update the model**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table): void {
            // Provenance, never a scope (ADR-0050 §1): which brand's host the
            // request arrived on, so the acknowledgement renders in that
            // brand's layout. Nullable because a request filed on the admin
            // host has no brand at all (ADR-0029).
            $table->ulid('filed_on_brand_id')->nullable()->after('id');

            // A subject with no account (ADR-0050 §3).
            $table->string('subject_email')->nullable()->after('subject_id');
        });

        // Expand/contract (ADR-0049 §9): populate the new column while the
        // old one is still read, so a pre-upgrade pod and a post-upgrade pod
        // both work. `brand_id` is dropped a release later.
        DB::statement('UPDATE data_subject_requests SET filed_on_brand_id = brand_id');

        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->ulid('subject_id')->nullable()->change();
            $table->index('subject_email');
        });

        // Exactly one of the two identifies the subject. A check constraint
        // rather than model validation: this is an invariant of the row, and
        // a background job writing directly must not be able to break it.
        DB::statement(
            'ALTER TABLE data_subject_requests ADD CONSTRAINT data_subject_requests_subject_identified'
            .' CHECK ((subject_id IS NULL) <> (subject_email IS NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE data_subject_requests DROP CONSTRAINT data_subject_requests_subject_identified');

        Schema::table('data_subject_requests', function (Blueprint $table): void {
            $table->dropIndex(['subject_email']);
            $table->dropColumn(['filed_on_brand_id', 'subject_email']);
        });
    }
};
```

In `DataSubjectRequest`: remove `use BelongsToBrand;` and its import, add `'subject_email'` and `'filed_on_brand_id'` to `$fillable`, update the `@property` block (`?string $subject_id`, `?string $subject_email`, `?string $filed_on_brand_id`), and replace the `subject_id` docblock paragraph with a sentence naming ADR-0050 §3. Add a helper the later tasks use:

```php
    /**
     * Who this request is about (ADR-0050 §3). The check constraint
     * guarantees exactly one column is set, so this is total.
     */
    public function subject(): SubjectRef
    {
        return $this->subject_id !== null
            ? SubjectRef::forUser($this->subject_id)
            : SubjectRef::forEmail((string) $this->subject_email);
    }
```

In `OpenSubjectRequest`, change the signature to take `SubjectRef $subject` and `?string $filedOnBrandId = null`, write `subject_id`/`subject_email` from it, and set `filed_on_brand_id`. In `dispatchFanOut`, the events keep carrying `brandId` — pass `$request->filed_on_brand_id ?? ''`. Update `DataSubjectRequestFactory` to set `subject_id` and leave `subject_email` null by default, and add a `->forEmail()` state.

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS. Every caller of `OpenSubjectRequest` must be updated — `DataRequestController::store` wraps its user id in `SubjectRef::forUser(...)`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add -A app-modules/compliance
git commit -m "A request belongs to a person and remembers where it was filed"
```

---

### Task 4: The verification row — an address held for one round-trip

Implements ADR-0050 decisions 4 and 6. Creates the table and its sweep; the HTTP surface is Task 5.

**Files:**
- Create: `app-modules/compliance/database/migrations/2026_09_01_110000_create_data_subject_request_verifications_table.php`
- Create: `app-modules/compliance/src/Models/DataSubjectRequestVerification.php`
- Create: `app-modules/compliance/database/factories/DataSubjectRequestVerificationFactory.php`
- Create: `app-modules/compliance/src/Console/SweepUnverifiedRequestsCommand.php`
- Modify: `app-modules/compliance/src/Providers/ComplianceServiceProvider.php`
- Test: `app-modules/compliance/tests/RequestVerificationSweepTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `DataSubjectRequestVerification` with `public const int TTL_HOURS = 24;`, static `issue(string $email, DataSubjectRequestType $type, ?string $brandId): array{0: self, 1: string}` returning the row and the **plaintext** token, and static `findByToken(string $plainToken): ?self`. Console command signature `compliance:sweep-unverified-requests`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequestVerification;

it('stores only a hash of the token', function (): void {
    $brand = $this->withBrand();

    [$row, $plain] = DataSubjectRequestVerification::issue(
        'someone@example.de',
        DataSubjectRequestType::Export,
        $brand->getKey(),
    );

    expect($plain)->not->toBe($row->token_hash)
        ->and($row->token_hash)->toBe(hash('sha256', $plain))
        ->and($row->expires_at->diffInHours($row->created_at))->toBe(-24.0)
        // A database read must not hand out working links.
        ->and(DataSubjectRequestVerification::query()->where('token_hash', $plain)->exists())->toBeFalse()
        ->and(DataSubjectRequestVerification::findByToken($plain)?->getKey())->toBe($row->getKey());
});

it('deletes unverified rows past their expiry and keeps live ones', function (): void {
    $brand = $this->withBrand();

    [$stale] = DataSubjectRequestVerification::issue('old@example.de', DataSubjectRequestType::Export, $brand->getKey());
    $stale->forceFill(['expires_at' => now()->subHour()])->save();

    [$live] = DataSubjectRequestVerification::issue('new@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->artisan('compliance:sweep-unverified-requests')->assertExitCode(0);

    expect(DataSubjectRequestVerification::query()->find($stale->getKey()))->toBeNull()
        ->and(DataSubjectRequestVerification::query()->find($live->getKey()))->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=RequestVerificationSweepTest`
Expected: FAIL — model not found.

- [ ] **Step 3: Write the migration, model, factory and command**

```php
// migration
Schema::create('data_subject_request_verifications', function (Blueprint $table): void {
    $table->ulid('id')->primary();

    // Provenance for the mail's layout, like the request's own column.
    // Nullable: the admin host resolves no brand (ADR-0029).
    $table->ulid('filed_on_brand_id')->nullable();

    // The whole reason this table exists, and the reason it is swept: an
    // address typed by a stranger, held on a stranger's say-so until the
    // link is followed (ADR-0050 §6). Not brand-scoped — the request it
    // becomes is not either.
    $table->string('email');
    $table->index('email');

    // DataSubjectRequestType: export|deletion.
    $table->string('type');

    // SHA-256, never the plaintext — same shape as EntranceDevice's bearer
    // token. High entropy makes a slow hash pointless; deterministic
    // hashing is what allows a single indexed lookup.
    $table->string('token_hash')->unique();

    $table->timestamp('expires_at');
    $table->timestamps();
});
```

```php
// model — the parts that carry a decision
final class DataSubjectRequestVerification extends Model
{
    use HasFactory, HasUlids;

    /** ADR-0050 §6: long enough for a mail read the next morning. */
    public const int TTL_HOURS = 24;

    protected $fillable = ['filed_on_brand_id', 'email', 'type', 'token_hash', 'expires_at'];

    /** @return array{0: self, 1: string} the row, and the plaintext token */
    public static function issue(string $email, DataSubjectRequestType $type, ?string $brandId): array
    {
        $plain = Str::random(64);

        $row = self::create([
            'filed_on_brand_id' => $brandId,
            'email' => mb_strtolower(trim($email)),
            'type' => $type,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return [$row, $plain];
    }

    public static function findByToken(#[SensitiveParameter] string $plainToken): ?self
    {
        /** @var self|null */
        return self::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('expires_at', '>', now())
            ->first();
    }

    protected function casts(): array
    {
        return ['type' => DataSubjectRequestType::class, 'expires_at' => 'datetime'];
    }
}
```

```php
// command
final class SweepUnverifiedRequestsCommand extends Command
{
    protected $signature = 'compliance:sweep-unverified-requests';

    protected $description = 'Delete unverified data-subject-request addresses past their 24-hour expiry';

    public function handle(): int
    {
        // Deleted, never anonymized: an unverified address is not a record
        // of anything that happened. Nobody proved it was theirs.
        $deleted = DataSubjectRequestVerification::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Deleted {$deleted} unverified request address(es).");

        return self::SUCCESS;
    }
}
```

Register in `ComplianceServiceProvider`: add the command to its `$commands` registration alongside the existing two, and schedule it hourly next to the existing Compliance schedule entries.

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=RequestVerificationSweepTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add -A app-modules/compliance
git commit -m "An unverified address lives one round-trip and one day"
```

---

### Task 5: The public page — form, submit, and a link that opens nothing until followed

Implements ADR-0050 decisions 4, 5 and 10.

**Files:**
- Create: `app-modules/compliance/src/Http/Controllers/PublicDataRequestController.php`
- Create: `app-modules/compliance/src/Data/RequestVerificationMailData.php`
- Create: `app-modules/compliance/resources/js/pages/privacy/RequestData.vue`
- Modify: `app-modules/compliance/routes/web/compliance.php`
- Modify: `app-modules/compliance/src/Providers/ComplianceServiceProvider.php` (two templates)
- Modify: `app-modules/compliance/src/Listeners/NotifySubjectOfRequestOutcome.php`
- Test: `app-modules/compliance/tests/PublicDataRequestTest.php` (create)

**Interfaces:**
- Consumes: `DataSubjectRequestVerification::issue()` / `findByToken()` (Task 4), `OpenSubjectRequest` with its Task 3 signature, `SubjectRef::forEmail()` (Task 1).
- Produces: routes named `data-requests.public.create`, `data-requests.public.store`, `data-requests.public.verify`. Template keys `compliance.request-verification` and `compliance.request-acknowledged` as public constants on `NotifySubjectOfRequestOutcome`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestVerification;
use Konventix\Identity\Models\User;

it('answers identically whether or not the address is known', function (): void {
    $this->withBrand();
    $known = User::factory()->create(['email' => 'known@example.de']);

    $first = $this->post('/privacy/request', ['email' => $known->email, 'type' => 'export']);
    $second = $this->post('/privacy/request', ['email' => 'stranger@example.de', 'type' => 'export']);

    // Identical status and identical redirect: anything else is an
    // account-enumeration oracle on a public URL (ADR-0050 §5).
    expect($first->getStatusCode())->toBe($second->getStatusCode())
        ->and($first->headers->get('Location'))->toBe($second->headers->get('Location'));
});

it('creates no request until the link is followed', function (): void {
    $this->withBrand();

    $this->post('/privacy/request', ['email' => 'someone@example.de', 'type' => 'export']);

    // The clock must not start before the request exists (ADR-0050 §4).
    expect(DataSubjectRequest::query()->count())->toBe(0)
        ->and(DataSubjectRequestVerification::query()->count())->toBe(1);
});

it('opens the request when the link is followed and spends the token', function (): void {
    $brand = $this->withBrand();
    [$row, $plain] = DataSubjectRequestVerification::issue('someone@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->get("/privacy/request/{$plain}")->assertOk();

    $request = DataSubjectRequest::query()->firstOrFail();

    expect($request->subject_email)->toBe('someone@example.de')
        ->and($request->subject_id)->toBeNull()
        ->and($request->filed_on_brand_id)->toBe($brand->getKey())
        // Deletion of the row is the replay guard: the token is spent.
        ->and(DataSubjectRequestVerification::query()->find($row->getKey()))->toBeNull();

    $this->get("/privacy/request/{$plain}")->assertNotFound();
});

it('resolves the address to an account when one exists', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create(['email' => 'known@example.de']);
    [, $plain] = DataSubjectRequestVerification::issue('known@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->get("/privacy/request/{$plain}")->assertOk();

    // Indistinguishable from a self-service request from here on
    // (ADR-0050 §3).
    expect(DataSubjectRequest::query()->firstOrFail()->subject_id)->toBe($user->getKey());
});

it('throttles repeated submissions for one address', function (): void {
    $this->withBrand();

    foreach (range(1, 3) as $ignored) {
        $this->post('/privacy/request', ['email' => 'victim@example.de', 'type' => 'export']);
    }

    // Per-address as well as per-IP: per-IP alone lets a distributed
    // sender mail one victim repeatedly (ADR-0050 §5).
    $this->post('/privacy/request', ['email' => 'victim@example.de', 'type' => 'export'])
        ->assertStatus(429);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=PublicDataRequestTest`
Expected: FAIL — 404 on every route.

- [ ] **Step 3: Write the controller, routes, templates and page**

Controller — the three actions, with the enumeration-safe response and the per-address limiter:

```php
final class PublicDataRequestController
{
    /** Per address, on top of the per-IP `throttle:` middleware. */
    private const int PER_EMAIL_ATTEMPTS = 3;

    public function create(): Response
    {
        return Inertia::render('privacy/RequestData');
    }

    public function store(Request $request, SendMessage $sendMessage): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'type' => ['required', 'in:export,deletion'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $key = 'dsr-public:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::PER_EMAIL_ATTEMPTS)) {
            abort(429);
        }

        RateLimiter::hit($key, 3600);

        [, $plain] = DataSubjectRequestVerification::issue(
            $email,
            DataSubjectRequestType::from($validated['type']),
            app(BrandContext::class)->current()?->getKey(),
        );

        // Best-effort, and deliberately not reported: whether the mail was
        // sent is not something this response may reveal (ADR-0050 §5).
        try {
            $sendMessage->handle(new SendMessageRequest(
                recipientId: null,
                templateKey: NotifySubjectOfRequestOutcome::REQUEST_VERIFICATION,
                payload: new RequestVerificationMailData(
                    requestType: $validated['type'],
                    verifyUrl: route('data-requests.public.verify', ['token' => $plain]),
                    expiresInHours: DataSubjectRequestVerification::TTL_HOURS,
                ),
                recipientEmail: $email,
            ));
        } catch (Throwable $exception) {
            Log::warning('Could not send a data-request verification mail.', ['reason' => $exception->getMessage()]);
        }

        // One response, always the same one.
        return to_route('data-requests.public.create')->with('status', 'verification-sent');
    }

    public function verify(string $token, OpenSubjectRequest $openSubjectRequest): Response
    {
        $verification = DataSubjectRequestVerification::findByToken($token);

        abort_if($verification === null, 404);

        // An address that turns out to have an account becomes an ordinary
        // ULID-backed request (ADR-0050 §3).
        $userId = User::query()->where('email', $verification->email)->value('id');

        $subject = $userId !== null
            ? SubjectRef::forUser((string) $userId)
            : SubjectRef::forEmail($verification->email);

        $request = $openSubjectRequest(
            $subject,
            $verification->type,
            $verification->type === DataSubjectRequestType::Deletion ? DeletionMode::Anonymize : null,
            $verification->filed_on_brand_id,
        );

        // Spending the token IS the replay guard, and the address has no
        // further purpose here — the request carries it now.
        $verification->delete();

        return Inertia::render('privacy/RequestData', [
            'opened' => ['deadline' => $request->deadline_at->toDateString()],
        ]);
    }
}
```

`User` is Identity's model — importing it into Compliance would violate ADR-0004. Resolve the address through Identity instead: add a `resolveUserIdByEmail(string $email): ?string` binding that Identity registers from its own provider, and have Compliance depend on that contract. Define it as `Konventix\Compliance\Gdpr\SubjectResolver` (interface, in Compliance) with `IdentitySubjectResolver` implementing it in Identity, registered from `IdentityServiceProvider`.

Routes — public, outside the `auth` group, with per-IP throttling:

```php
// Public Art. 15 / Art. 17 entry (ADR-0050 §4). Deliberately outside
// `auth`: the obligation does not depend on whether the subject can log in.
Route::get('/privacy/request', [PublicDataRequestController::class, 'create'])
    ->name('data-requests.public.create');

Route::post('/privacy/request', [PublicDataRequestController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('data-requests.public.store');

Route::get('/privacy/request/{token}', [PublicDataRequestController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('data-requests.public.verify');
```

Two templates in `registerMessageTemplates()`, both `logBodies: true`, both with no topic, `de` default — matching the three already there. `compliance.request-verification` renders `$verifyUrl` and `$expiresInHours`; `compliance.request-acknowledged` renders `$requestType` and `$deadline`.

The Vue page is a small form (email input, export/deletion radio, submit) plus two flash states: "check your mail" after submit, and the deadline after a verified link. Keep it under 150 lines; use Wayfinder helpers for the URLs, never a hardcoded path.

- [ ] **Step 4: Build the frontend and run tests**

```bash
docker compose exec -T laravel.test npm run build
docker compose exec -T laravel.test php artisan test --compact --filter=PublicDataRequestTest
```
Expected: PASS, 5 tests. Without the build the page tests 500 on a missing Vite manifest entry.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec -T laravel.test npm run lint:check && docker compose exec -T laravel.test npm run types:check
git add -A app-modules/compliance app-modules/identity
git commit -m "Anyone may ask what we hold, and nobody may ask on someone else's behalf"
```

---

### Task 6: An account-less subject collects the archive

Implements ADR-0050 decision 9. The authenticated download path is untouched.

**Files:**
- Create: `app-modules/compliance/database/migrations/2026_09_01_120000_add_archive_token_to_data_subject_requests.php`
- Modify: `app-modules/compliance/src/Models/DataSubjectRequest.php`
- Modify: `app-modules/compliance/src/Http/Controllers/DataRequestController.php`
- Modify: `app-modules/compliance/src/Listeners/NotifySubjectOfRequestOutcome.php`
- Modify: `app-modules/compliance/routes/web/compliance.php`
- Test: `app-modules/compliance/tests/AccountLessArchiveTest.php` (create)

**Interfaces:**
- Consumes: `DataSubjectRequest::subject()` (Task 3), `SubjectRef::isAccountBacked()` (Task 1).
- Produces: `DataSubjectRequest::issueArchiveToken(): string` (returns plaintext, stores `archive_token_hash`), route `data-requests.public.download`.

- [ ] **Step 1: Write the failing test**

```php
it('serves an email-only subject their archive once, then never again', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    $request = DataSubjectRequest::create([
        'subject_email' => 'someone@example.de',
        'filed_on_brand_id' => $brand->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
        'archive_path' => 'subject-exports/a.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->addDays(14),
    ]);
    Storage::disk('exports')->put('subject-exports/a.json', '{"modules":{}}');

    $token = $request->issueArchiveToken();

    $this->get("/privacy/request/archive/{$token}")->assertOk();
    // Single use: a leaked link is already spent (ADR-0050 §9).
    $this->get("/privacy/request/archive/{$token}")->assertNotFound();
});

it('refuses an archive token once the archive has expired', function (): void {
    $brand = $this->withBrand();
    $request = DataSubjectRequest::create([
        'subject_email' => 'someone@example.de',
        'filed_on_brand_id' => $brand->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
        'archive_path' => 'subject-exports/a.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->subDay(),
    ]);

    $this->get('/privacy/request/archive/'.$request->issueArchiveToken())->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=AccountLessArchiveTest`
Expected: FAIL — `issueArchiveToken()` undefined.

- [ ] **Step 3: Implement**

Migration adds `archive_token_hash` (nullable string, unique). Model:

```php
    /**
     * A single-use link for a subject who cannot sign in (ADR-0050 §9).
     * Returns the plaintext once; only the hash is stored. Consuming the
     * link clears the hash, so a forwarded mail is already spent.
     */
    public function issueArchiveToken(): string
    {
        $plain = Str::random(64);
        $this->forceFill(['archive_token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }
```

Controller gains `downloadWithToken(string $token)`: look the request up by `archive_token_hash`, `abort_unless($model?->hasDownloadableArchive(), 404)`, clear the hash, stream the file. Route it under `throttle:10,1`. In `NotifySubjectOfRequestOutcome::handleExportCompleted`, branch on `$request->subject()->isAccountBacked()`: an account-backed subject gets today's mail pointing at Settings → Privacy; an email-only subject gets the same template with a `downloadUrl` built from a freshly issued token, sent with `recipientId: null, recipientEmail: $request->subject_email`.

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add -A app-modules/compliance
git commit -m "A subject with no account can still collect what we hold"
```

---

### Task 7: Every request type is acknowledged

Implements ADR-0050 decision 10. Today only deletion is acknowledged; Art. 12(3) does not distinguish.

**Files:**
- Modify: `app-modules/compliance/src/Listeners/NotifySubjectOfRequestOutcome.php`
- Modify: `app-modules/compliance/src/Providers/ComplianceServiceProvider.php`
- Test: `app-modules/compliance/tests/SubjectRequestNotificationTest.php` (extend)

**Interfaces:**
- Consumes: `NotifySubjectOfRequestOutcome::REQUEST_ACKNOWLEDGED` (registered in Task 5).
- Produces: `handleExportRequested(DataExportRequested $event): void`.

- [ ] **Step 1: Write the failing test**

```php
it('acknowledges an export request with its deadline', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forUser($user->getKey()),
        DataSubjectRequestType::Export,
        null,
        $brand->getKey(),
    );

    expect(MessageRecord::withoutBrandScope()
        ->where('template_key', NotifySubjectOfRequestOutcome::REQUEST_ACKNOWLEDGED)
        ->where('recipient_id', $user->getKey())
        ->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter="acknowledges an export request"`
Expected: FAIL — no such message record.

- [ ] **Step 3: Implement**

Add `handleExportRequested` to the listener, mirroring `handleDeletionRequested` but using `REQUEST_ACKNOWLEDGED`, and register it in `ComplianceServiceProvider`:

```php
Event::listen(DataExportRequested::class, [NotifySubjectOfRequestOutcome::class, 'handleExportRequested']);
```

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
git add -A app-modules/compliance
git commit -m "An export request is answered when it arrives, not only when it is done"
```

---

### Task 8: A policy declares its lawful basis

Implements ADR-0050 decision 7. Schema and enum only; the withdrawal behaviour is Task 9.

**Files:**
- Create: `app-modules/compliance/src/Enums/LawfulBasis.php`
- Create: `app-modules/compliance/database/migrations/2026_09_01_130000_add_lawful_basis_to_policy_versions.php`
- Modify: `app-modules/compliance/src/Models/PolicyVersion.php`, `src/Data/PolicyVersionData.php`, `src/Actions/{DraftPolicyVersion,UpdatePolicyVersion}.php`, `src/Http/Controllers/Admin/PolicyVersionController.php`
- Test: `app-modules/compliance/tests/LawfulBasisTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `LawfulBasis` enum with cases `Consent = 'consent'`, `Contract = 'contract'`, `LegitimateInterest = 'legitimate_interest'` and method `allowsSelfServiceWithdrawal(): bool` (true only for `Consent`). `PolicyVersion->lawful_basis` cast to it.

- [ ] **Step 1: Write the failing test**

```php
it('backfills every existing policy version to consent', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create();

    // The only basis that preserves today's unconditional self-service
    // behaviour exactly (ADR-0050 consequences).
    expect($version->fresh()->lawful_basis)->toBe(LawfulBasis::Consent);
});

it('permits self-service withdrawal only where the basis is consent', function (): void {
    expect(LawfulBasis::Consent->allowsSelfServiceWithdrawal())->toBeTrue()
        ->and(LawfulBasis::Contract->allowsSelfServiceWithdrawal())->toBeFalse()
        ->and(LawfulBasis::LegitimateInterest->allowsSelfServiceWithdrawal())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=LawfulBasisTest`
Expected: FAIL — enum not found.

- [ ] **Step 3: Implement**

```php
/**
 * Why a policy may process someone's data (Art. 6(1) GDPR), and therefore
 * whether leaving it is a right or a request (ADR-0050 §7).
 *
 * Art. 7(3) makes withdrawing consent as easy as giving it, so a
 * consent-based policy is always self-service and no setting may say
 * otherwise. Modelling the basis rather than the withdrawal mechanic is
 * what makes that configuration unrepresentable rather than merely
 * discouraged.
 */
enum LawfulBasis: string
{
    case Consent = 'consent';
    case Contract = 'contract';
    case LegitimateInterest = 'legitimate_interest';

    public function allowsSelfServiceWithdrawal(): bool
    {
        return $this === self::Consent;
    }
}
```

Migration: `$table->string('lawful_basis')->default('consent')->after('requires_consent');` — the default *is* the backfill, and it preserves today's behaviour for every row in flight. Add the cast, `$fillable` entry, `@property` line, and a `lawfulBasis` field on `PolicyVersionData` with `#[TypeScript]` already present on that class. The admin editor gets a select; label the options with what they mean, not the enum names.

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec -T laravel.test php artisan typescript:transform
git add -A app-modules/compliance resources/js/types
git commit -m "A policy says why it may hold you, and that decides how you leave"
```

---

### Task 9: Withdrawal follows the basis

Implements ADR-0050 decisions 7 and 8.

**Files:**
- Create: `app-modules/compliance/src/Events/ConsentWithdrawalRequested.php`
- Modify: `app-modules/compliance/src/Enums/DataSubjectRequestType.php`
- Modify: `app-modules/compliance/src/Actions/WithdrawConsent.php`
- Modify: `app-modules/compliance/src/Http/Controllers/ConsentController.php`, `PrivacyController.php`
- Modify: `app-modules/compliance/src/Actions/OpenSubjectRequest.php` (the `match` on type)
- Modify: `app-modules/compliance/resources/js/pages/settings/Privacy.vue`
- Test: `app-modules/compliance/tests/ConsentWithdrawalTest.php` (create)

**Interfaces:**
- Consumes: `LawfulBasis::allowsSelfServiceWithdrawal()` (Task 8), `OpenSubjectRequest` (Task 3), `SubjectRef::forUser()` (Task 1).
- Produces: `DataSubjectRequestType::ConsentWithdrawal = 'consent_withdrawal'`; `ConsentWithdrawalRequested(string $requestId, string $consentId, string $subjectId, string $policyId)`; `WithdrawConsent::__invoke(Consent $consent, ?CarbonInterface $withdrawnAt = null): Consent|DataSubjectRequest`.

- [ ] **Step 1: Write the failing test**

```php
it('withdraws immediately where the basis is consent', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Consent]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $result = app(WithdrawConsent::class)($consent);

    expect($result)->toBeInstanceOf(Consent::class)
        ->and($result->withdrawn_at)->not->toBeNull();
});

it('opens a request instead where the basis is contract', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create(['lawful_basis' => LawfulBasis::Contract]);
    $consent = Consent::factory()->create(['policy_version_id' => $version->getKey()]);

    $result = app(WithdrawConsent::class)($consent);

    // Art. 7(3) applies to consent, and this policy's basis is not consent:
    // leaving it is a request the operator answers (ADR-0050 §7).
    expect($result)->toBeInstanceOf(DataSubjectRequest::class)
        ->and($result->type)->toBe(DataSubjectRequestType::ConsentWithdrawal)
        ->and($consent->fresh()->withdrawn_at)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ConsentWithdrawalTest`
Expected: FAIL — `LawfulBasis` column unread; both cases withdraw.

- [ ] **Step 3: Implement**

Add the enum case (with a docblock noting the name says "consent" explicitly because `PolicyWithdrawn` means a policy being retired), add `DataSubjectRequestType::ConsentWithdrawal => null` to `OpenSubjectRequest::dispatchFanOut`'s `match` so it opens without a fan-out, and gate `WithdrawConsent`:

```php
        $consent->loadMissing('policyVersion');

        if (! $consent->policyVersion->lawful_basis->allowsSelfServiceWithdrawal()) {
            $request = $this->openSubjectRequest(
                SubjectRef::forUser($consent->subject_id),
                DataSubjectRequestType::ConsentWithdrawal,
                null,
                $consent->brand_id,
            );

            event(new ConsentWithdrawalRequested(
                requestId: $request->getKey(),
                consentId: $consent->getKey(),
                subjectId: $consent->subject_id,
                policyId: $consent->policyVersion->policy_id,
            ));

            return $request;
        }

        // ... existing immediate-withdrawal body
```

`PrivacyController` adds a `withdrawalPending` boolean per consent (an open `ConsentWithdrawal` request naming it) and a `selfServiceWithdrawal` flag from the basis; `Privacy.vue` renders "Withdrawal pending" instead of the button, and where the basis is not consent the button's label says the request is a request. The console needs no new screen: accepting the request calls `WithdrawConsent` again after the basis check is satisfied by the operator's decision — implement that as an explicit `ApproveConsentWithdrawal` action so the operator path does not re-enter the guard.

- [ ] **Step 4: Run tests**

Run: `docker compose exec -T laravel.test npm run build && docker compose exec -T laravel.test php artisan test --compact app-modules/compliance/tests`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint --dirty
docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G
git add -A app-modules/compliance
git commit -m "Leaving a policy is a right where it was consent and a request where it was not"
```

---

### Task 10: Close the loop — docs, TODO, ROADMAP

**Files:**
- Modify: `app-modules/compliance/TODO.md`, `app-modules/identity/TODO.md`, `docs/ROADMAP.md`, `docs/architecture/module-map.md` (if it names the fan-out signature)

- [ ] **Step 1: Update Compliance's TODO**

Strike the `AssembleSubjectExport` brand-scope item (lines ~53–58) and the withdrawal item (~line 81); add any follow-up the implementation surfaced. Add an item recording that Commerce cannot answer for an email-only subject and Newsletter has no handler at all.

- [ ] **Step 2: Update Identity's TODO**

Remove the per-brand-deletion note; the last-membership rule is gone.

- [ ] **Step 3: Mark slice 20 done in the ROADMAP**

Add a `**Done** (ADR-0050)` marker to slice 20 in the same voice as slices 18–24, and strike the "subject request is still brand-scoped" entry from Known gaps.

- [ ] **Step 4: Run the whole suite once**

```bash
docker compose exec -T laravel.test npm run build:ssr
docker compose exec -T laravel.test bash -lc 'nohup php artisan inertia:start-ssr > /tmp/ssr.log 2>&1 & sleep 3'
docker compose exec -T laravel.test php artisan test --compact
```
Expected: PASS. The SSR server is required or `SettingsLayoutTest`'s ten cases fail on server-rendered HTML that never rendered.

- [ ] **Step 5: Commit**

```bash
git add -A docs app-modules/*/TODO.md
git commit -m "The docs say what this branch settled about a person's data"
```

---

## Self-Review

**Spec coverage.** ADR-0050 §1 → Tasks 2, 3. §2 → Task 2. §3 → Tasks 1, 3, 5. §4 → Tasks 4, 5. §5 → Task 5. §6 → Task 4. §7 → Tasks 8, 9. §8 → Task 9. §9 → Task 6. §10 → Task 7. Consequences (docs, TODOs) → Task 10. No decision is unimplemented.

**Type consistency.** `SubjectRef::forUser`/`forEmail`/`isAccountBacked`/`key` are defined in Task 1 and used with those names in Tasks 2, 3, 5, 6, 9. `OpenSubjectRequest`'s four-argument signature is introduced in Task 3 and called with four arguments in Tasks 5, 7, 9. `DataSubjectRequestVerification::issue` returns `[row, plaintext]` in Task 4 and is destructured that way in Tasks 4 and 5. `LawfulBasis::allowsSelfServiceWithdrawal` is defined in Task 8 and called in Task 9.

**Known risks, carried deliberately.**
1. **Task 2 has no green intermediate state.** PHP interfaces have no defaults, so nine implementations move in one commit. If it must be broken up, the only safe seam is a temporary `GdprHandlerV2` interface with the registry preferring it — more machinery than the change deserves.
2. **A handler that keeps a brand filter still compiles.** The `where('brand_id', ...)` lines are deleted by hand across eight files, and nothing but the two-brand test in Task 2 Step 1 catches a missed one. Review that diff line by line.
3. **`Str::random()` is `random_bytes`-backed** and correct for tokens here; do not substitute `Str::uuid()` or anything time-seeded.
4. **The public page is a new unauthenticated mail-sending surface.** Its rate limits are load-bearing, not hygiene — the throttle test in Task 5 must not be weakened to make a suite run faster.
5. **Identity's resolver crosses a module boundary.** Compliance must not import `Konventix\Identity\Models\User` (ADR-0004); the `SubjectResolver` interface in Task 5 is what keeps that legal. A reviewer should check no `use Konventix\Identity\` appears under `app-modules/compliance/src/`.
