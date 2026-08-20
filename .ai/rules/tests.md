---
paths:
  - 'app-modules/*/tests/**'
---

# Tests

## Never call Event::fake() without arguments — it breaks BelongsToBrand
Blanket Event::fake() also swallows eloquent.* model events, so the BelongsToBrand creating hook never stamps brand_id and inserts die on the not-null constraint (ULIDs survive — they are set in performInsert, not via events). Always pass the specific event classes: Event::fake([RegistrationConfirmed::class]). This bit two modules in one day.

## Pest helper functions cannot call protected TestCase methods
Plain `function` helpers in a Pest file run in global scope — `$test->withBrand()` on the passed TestCase fails with "Call to protected method". Call `$this->withBrand()` inside the test closure and pass the resulting model into the helper. Also: helper function names are process-global across ALL module test files in one run — prefix or scope them uniquely or the suite dies on redeclaration.

## The test environment is Postgres with a sync queue — both change what you assert
phpunit.xml overrides DB_DATABASE only, never DB_CONNECTION: tests run on Postgres, so exclusion constraints (ADR-0020) and `UNIQUE NULLS NOT DISTINCT` behave for real — and a constraint violation aborts the surrounding transaction, so a test cannot query the database after asserting a 500 from one. QUEUE_CONNECTION=sync runs jobs inline, so an action that dispatches a fan-out completes it during the call; use Queue::fake() where a test asserts the state BEFORE the handlers run. MAIL_MAILER=array means mail is safe to exercise for real — assert with Mail::mailer()->getSymfonyTransport()->messages(). These apply to the root tests/ directory too, which this file's path glob does not match.

## A new module page needs `npm run build`, and an admin-host request resolves no brand
Inertia resolves pages through the Vite manifest, so a feature test hitting a page added this session fails until `npm run build` has run — the failure looks like a 500, not a missing file. Separately: a request to the operator admin host leaves NO resolved brand (ADR-0029) and BrandScope hardens brand-owned queries to empty, so a test needing a brand-scoped factory row after such a request must create the row BEFORE it. `operators.admin_host` is unique — a test creating two operators needs two distinct hosts.
