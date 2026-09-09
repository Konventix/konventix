---
paths:
  - 'app-modules/*/tests/**'
  - 'tests/**'
---

# Tests

## Never call Event::fake() without arguments — it breaks BelongsToBrand
Blanket Event::fake() also swallows eloquent.* model events, so the BelongsToBrand creating hook never stamps brand_id and inserts die on the not-null constraint (ULIDs survive — they are set in performInsert, not via events). Always pass the specific event classes: Event::fake([RegistrationConfirmed::class]). This bit two modules in one day.

## Pest helper functions cannot call protected TestCase methods
Plain `function` helpers in a Pest file run in global scope — `$test->withBrand()` on the passed TestCase fails with "Call to protected method". Call `$this->withBrand()` inside the test closure and pass the resulting model into the helper. Also: helper function names are process-global across ALL module test files in one run — prefix or scope them uniquely or the suite dies on redeclaration. And a helper that a *second* file calls must live in the module's `tests/Helpers.php` (loaded for every process from `tests/Pest.php`), never inside a `*Test.php`: under `--parallel` each worker loads only the test files it was handed, so `emptySubmission()` declared in SubmitRegistrationTest.php did not exist in the worker running SalesWindowTest.php — nine helpers across four modules failed that way, and only ever worked because one process loaded every file in alphabetical order. A class declared in a test file (`CartCheckoutTestFakeStripe`) is the same case.

## The test environment is Postgres with a sync queue — both change what you assert
phpunit.xml overrides DB_DATABASE only, never DB_CONNECTION: tests run on Postgres, so exclusion constraints (ADR-0020) and `UNIQUE NULLS NOT DISTINCT` behave for real — and a constraint violation aborts the surrounding transaction, so a test cannot query the database after asserting a 500 from one. QUEUE_CONNECTION=sync runs jobs inline, so an action that dispatches a fan-out completes it during the call; use Queue::fake() where a test asserts the state BEFORE the handlers run. MAIL_MAILER=array means mail is safe to exercise for real — assert with Mail::mailer()->getSymfonyTransport()->messages().

## A new module page needs `npm run build`, and an admin-host request resolves no brand
Inertia resolves pages through the Vite manifest, so a feature test hitting a page added this session fails until `npm run build` has run — the failure looks like a 500, not a missing file. Separately: a request to the operator admin host leaves NO resolved brand (ADR-0029) and BrandScope hardens brand-owned queries to empty, so a test needing a brand-scoped factory row after such a request must create the row BEFORE it. `operators.admin_host` is unique — a test creating two operators needs two distinct hosts.

## The invoices disk is faked for every test, and afterCommit jobs run synchronously under RefreshDatabase
`TestCase::setUp()` calls `Storage::fake(config('filesystems.invoices_disk', 'invoices'))` unconditionally, after `parent::setUp()`: `GenerateInvoiceDocument`/`GenerateCreditNoteDocument` dispatch `afterCommit()` from inside a `DB::transaction()` that is itself nested inside RefreshDatabase's own wrapping transaction, which never reaches level 0 during a test — so the dispatch actually runs synchronously, in-process, on any test that reaches it, real queue driver or not. Without the global fake this wrote real PDFs onto the host's `storage/app/invoices` on every run. A test asserting the archive still calls `Storage::fake('invoices')` itself (re-faking the same disk is harmless) so its assertions read what it just faked. The same synchronous-dispatch fact means `Konventix\Platform\Brands\PropagatesBrandContextToQueue` restores the ambient brand once such a job finishes rather than forgetting it outright — `BrandContext` comes back to whatever the dispatching code had before the job interrupted it, so a test that dispatches an `afterCommit()` job and then creates another brand-owned row no longer needs a `Queue::fake()` or a repeat `$this->withBrand()` call just to survive the dispatch.

## The suite runs `--parallel`; never start two *independent* test runs at once
`php artisan test --parallel` is the full suite's normal form, locally (`composer test`) and in CI (#140): paratest starts one worker per core, and Laravel gives each its own database, `testing_test_1…n`, created and migrated beside `testing` — the workers never see each other's tables. Measured on 8 workers: 581s of single-process suite (pipeline 1609) to about 90s locally. Two things it needs. Postgres must allow the lock slots eight concurrent `migrate:fresh` runs take — `max_locks_per_transaction=512`, set on the pgsql service in compose.yaml and on the CI service; with the default 64 the symptom is `SQLSTATE[53200] out of shared memory` on `drop table`, dozens of tests at once. And every helper a second file calls lives in `tests/Helpers.php` (above). What has not changed: two *independent* runs — a full run in the background plus a filtered one in the foreground — still drop each other's tables mid-run and scatter "relation ... does not exist" QueryExceptions over tests that have nothing to do with your change; run them one after the other. Tests must run inside the container (`vendor/bin/sail artisan test`) — from the host, `DB_HOST=pgsql` does not resolve.

## `composer verify` is the fast run, and the full suite is owed only when it says so
`vendor/bin/sail composer verify` runs pint on the changed PHP files, phpstan, and only the test directories the changed paths map to — `contrib/verify-plan.sh` is the mapping and `tests/Unit/VerifyPlanTest.php` pins it. A change inside `app-modules/<m>/` runs `app-modules/<m>/tests`, `app/` runs the root suites, `resources/js` runs vitest; anything that every test reads (config, routes, database, the lockfile, the platform module, `tests/TestCase.php`, `tests/Pest.php`) marks the full suite as owed, and `verify` prints that instead of paying it. When a new top-level directory appears, add its mapping there or its tests never run locally. The full suite (`composer test`) is for that owed case and for the moment before the merge request; the pipeline runs it on every merge request regardless.

## A test that needs a second process must leave RefreshDatabase's transaction
tests/Pest.php applies RefreshDatabase by path to everything under app-modules/,
and Pest cannot unbind a trait for one file — adding DatabaseTruncation stacks
inside the wrapping transaction rather than replacing it (setUpTraits calls
refreshDatabase() before truncateDatabaseTables()). A test that spawns a real
process (Symfony Process, `php artisan`) must therefore call `DB::rollBack()`
in its own beforeEach: everything after that line commits, so the child on its
own PDO can see it. Cleanup is Laravel's — beginDatabaseTransaction's teardown
notices the connection is no longer in a transaction and sets
RefreshDatabaseState::$migrated = false, so the next test re-migrates. The child
must also be handed the *resolved* DB config as environment variables
(DB_DATABASE=testing from phpunit.xml, DB_URL blank so an inherited URL cannot
outrank the parts), or it will silently probe the Sail dev database instead.
See app-modules/commerce/tests/InvoiceNumberingProbeTest.php.

## `Artisan::call()` fires no `CommandStarting` under the test runner
`Illuminate\Foundation\Console\Kernel::__construct` bridges Symfony's console events to `CommandStarting`/`CommandFinished` only when `runningUnitTests()` is false, so a listener on either event is silently never reached by `Artisan::call()` in a Pest test, however correctly it is wired — `CorrelationIdTest` found this when its command probe logged without an ID while the same call in `sail artisan tinker` fired the event fine. To exercise the real path: `app(Kernel::class)->rerouteSymfonyCommandEvents()`, then `setArtisan(null)` so the next `getArtisan()` builds an instance with the dispatcher attached (RefreshDatabase's `migrate:fresh` may already have built one without it), then `registerCommand()`/`call()`. Do not make that bridge permanent in `TestCase`: it would fire for every `artisan('migrate')` too and change what every test's context holds.
