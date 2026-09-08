---
paths:
  - 'app-modules/*/src/**'
---

# App Modules Src

## BrandScope hardens to zero rows on the admin host — escape it in relation counts too
On the operator admin host BrandScope adds WHERE 1=0 (ADR-0029 hardening). withoutBrandScope() on the parent query is NOT enough: relation queries, withCount() subqueries and $model->relation()->count() re-apply the scope on the related model and silently read 0/empty. Any admin-host code touching brand-owned relations must escape per relation: withCount(['x' => fn ($q) => $q->withoutGlobalScope(BrandScope::class)]) or RelatedModel::withoutBrandScope()->where(...). This deleted referenced media files in dev before a test caught it.

The escape is owed on **listener paths too**, not only in controllers. A Commerce event consumed synchronously inside a console request runs on the admin host, so `Registration::withoutBrandScope()` followed by `loadMissing('registrationType')` still reads null — and escaping the parent relation alone is not enough either: `$type->quotas()` re-applies the scope on the *related* model, so the read comes back empty and a capacity check passes vacuously. Escape on every level, including inside a nested `whereHas('a.b', ...)`. See `Konventix\Registration\Actions\Concerns\LoadsRegistrationType` and `PromoteFromWaitlist`. This ate an 11.90 EUR Stripe refund on dev and then swallowed the seat it freed (#236).

## A Blade directive glued to a preceding/following word is left as literal text — MessageTemplate subjects and bodies both go through Blade::render()
Messaging's `TemplateRenderer` runs both the subject and the body through `Blade::render()` on a raw string. Blade's directive regex only recognises `@word` when it has a non-word boundary on both sides: `bezahlt@else` and `AAA@endif` (no space/newline before `@else`/`@endif`) do not match, so the directive is left as literal `@else`/`@endif` text and everything from the previous directive onward silently fails to compile as intended — `@if($p)\nA\n@else\nB\n@endif`, or the same with spaces around each directive, compiles correctly. This is core Blade behaviour, not a package interaction — always put whitespace or a newline around every directive in a template string. Prefer two template keys over one template branching on `@if($paid)…@else…@endif` anyway (see `commerce.invoice-issued` vs `commerce.invoice-paid`, `CommerceServiceProvider::registerMessageTemplates()`) — the caller (e.g. `SendInvoiceMail`) picks the key, and per-brand template overrides (`TemplateOverride`) stay branch-free.

## A sweep asks the provider outside the row lock, and "I could not ask" never cancels
`ExpireCarts` and `ExpireRegistrationOrders` ask the PSP whether an order's open
hosted session was paid before they cancel it (#231,
`Actions\Concerns\AsksThePspBeforeCancelling`, `Settlement\HostedPaymentProbe`).
Two rules that cost real money on dev: the question happens **before** the
transaction, never while holding a row lock — a provider's latency would
otherwise block everything else that touches the order — and the answers are not
interchangeable. "Not asked" (the run's probe budget ran out), "could not be
reached", "the provider says the money is still travelling" and "nothing was
paid" are four different states, and only the last cancels immediately. A sweep
that cancels on "I do not know" releases the seat of an order somebody has paid
for, which is the failure the question was added to prevent. Every clock that
does eventually release such an order starts at the end of the *payment window*
(`Settlement\PaymentWindow::endsFor()`), not at `orders.created_at`: a checkout
hold is renewed on every new attempt, so the order's age is not the attempt's.
