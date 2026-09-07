---
paths:
  - 'app-modules/*/tests/**'
  - 'tests/**'
---

# Tests

## Never call Event::fake() without arguments — it breaks BelongsToBrand
Blanket Event::fake() also swallows eloquent.* model events, so the BelongsToBrand creating hook never stamps brand_id and inserts die on the not-null constraint (ULIDs survive — they are set in performInsert, not via events). Always pass the specific event classes: Event::fake([RegistrationConfirmed::class]). This bit two modules in one day.

## Pest helper functions cannot call protected TestCase methods
Plain `function` helpers in a Pest file run in global scope — `$test->withBrand()` on the passed TestCase fails with "Call to protected method". Call `$this->withBrand()` inside the test closure and pass the resulting model into the helper. Also: helper function names are process-global across ALL module test files in one run — prefix or scope them uniquely or the suite dies on redeclaration.

## The test environment is Postgres with a sync queue — both change what you assert
phpunit.xml overrides DB_DATABASE only, never DB_CONNECTION: tests run on Postgres, so exclusion constraints (ADR-0020) and `UNIQUE NULLS NOT DISTINCT` behave for real — and a constraint violation aborts the surrounding transaction, so a test cannot query the database after asserting a 500 from one. QUEUE_CONNECTION=sync runs jobs inline, so an action that dispatches a fan-out completes it during the call; use Queue::fake() where a test asserts the state BEFORE the handlers run. MAIL_MAILER=array means mail is safe to exercise for real — assert with Mail::mailer()->getSymfonyTransport()->messages().

## A new module page needs `npm run build`, and an admin-host request resolves no brand
Inertia resolves pages through the Vite manifest, so a feature test hitting a page added this session fails until `npm run build` has run — the failure looks like a 500, not a missing file. Separately: a request to the operator admin host leaves NO resolved brand (ADR-0029) and BrandScope hardens brand-owned queries to empty, so a test needing a brand-scoped factory row after such a request must create the row BEFORE it. `operators.admin_host` is unique — a test creating two operators needs two distinct hosts.

## The invoices disk is faked for every test, and afterCommit jobs run synchronously under RefreshDatabase
`TestCase::setUp()` calls `Storage::fake(config('filesystems.invoices_disk', 'invoices'))` unconditionally, after `parent::setUp()`: `GenerateInvoiceDocument`/`GenerateCreditNoteDocument` dispatch `afterCommit()` from inside a `DB::transaction()` that is itself nested inside RefreshDatabase's own wrapping transaction, which never reaches level 0 during a test — so the dispatch actually runs synchronously, in-process, on any test that reaches it, real queue driver or not. Without the global fake this wrote real PDFs onto the host's `storage/app/invoices` on every run. A test asserting the archive still calls `Storage::fake('invoices')` itself (re-faking the same disk is harmless) so its assertions read what it just faked. The same synchronous-dispatch fact means `Konventix\Platform\Brands\PropagatesBrandContextToQueue` restores the ambient brand once such a job finishes rather than forgetting it outright — `BrandContext` comes back to whatever the dispatching code had before the job interrupted it, so a test that dispatches an `afterCommit()` job and then creates another brand-owned row no longer needs a `Queue::fake()` or a repeat `$this->withBrand()` call just to survive the dispatch.

## Never run two test processes at once — they share one database
Tests run against the `testing` Postgres database in the pgsql container, and RefreshDatabase migrates it. Two `php artisan test` processes at the same time (a full run in the background plus a filtered one in the foreground) drop each other's tables mid-run: you get a scatter of "relation ... does not exist" QueryExceptions in tests that have nothing to do with your change. Run them serially. Also: tests must run inside the container (`docker compose exec -T laravel.test php artisan test`) — from the host, `DB_HOST=pgsql` does not resolve.

## `composer verify` is the fast run, and the full suite is owed only when it says so
`vendor/bin/sail composer verify` runs pint on the changed PHP files, phpstan, and only the test directories the changed paths map to — `contrib/verify-plan.sh` is the mapping and `tests/Unit/VerifyPlanTest.php` pins it. A change inside `app-modules/<m>/` runs `app-modules/<m>/tests`, `app/` runs the root suites, `resources/js` runs vitest; anything that every test reads (config, routes, database, the lockfile, the platform module, `tests/TestCase.php`, `tests/Pest.php`) marks the full suite as owed, and `verify` prints that instead of paying it. When a new top-level directory appears, add its mapping there or its tests never run locally. The full suite (`composer test`) is for that owed case and for the moment before the merge request; the pipeline runs it on every merge request regardless.

## `Artisan::call()` fires no `CommandStarting` under the test runner
`Illuminate\Foundation\Console\Kernel::__construct` bridges Symfony's console events to `CommandStarting`/`CommandFinished` only when `runningUnitTests()` is false, so a listener on either event is silently never reached by `Artisan::call()` in a Pest test, however correctly it is wired — `CorrelationIdTest` found this when its command probe logged without an ID while the same call in `sail artisan tinker` fired the event fine. To exercise the real path: `app(Kernel::class)->rerouteSymfonyCommandEvents()`, then `setArtisan(null)` so the next `getArtisan()` builds an instance with the dispatcher attached (RefreshDatabase's `migrate:fresh` may already have built one without it), then `registerCommand()`/`call()`. Do not make that bridge permanent in `TestCase`: it would fire for every `artisan('migrate')` too and change what every test's context holds.
