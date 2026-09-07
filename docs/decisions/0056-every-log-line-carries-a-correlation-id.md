# ADR-0056: Every log line carries a correlation ID

**Status:** accepted

## Context

A purchase is one request, a handful of queued jobs — the invoice document,
the mail, the admission — and the lines each of them writes to stderr. On
the cluster those lines are JSON records from several pods, interleaved
with everybody else's, and until now nothing tied them together: no request
ID anywhere, `config/logging.php` with only the PSR placeholder processor,
the brand the one thing that crossed the queue boundary
(`PropagatesBrandContextToQueue`). The cutover plan wants a purchase followed
from the ingress log through the application log to the job and the mail
(#157), a PSP test event found by its ID (#155), and the feedback function
(#88) needs the one field a user can quote that leads to the lines behind
their report. GitLab #89 is the issue.

Laravel 13 already ships the carrier. `Context` is a scoped repository whose
contents the framework copies into the `extra` of every Monolog record on
every channel, serialises into every queue payload and restores on
`JobProcessing`, flushing whatever the worker held before; Octane forgets it
per request. What was missing was only the decision what the value is and
who sets it.

## Decision

1. **One ID per unit of work, in `Context` under `correlation_id`.** A
   request, a queued job, a console command. `App\Observability\CorrelationId`
   owns the name, the shape and the rules; nothing else reads the header or
   writes the key.

2. **A request adopts the proxy's `X-Request-ID` or mints a ULID**, and
   answers with the ID in the same header. `X-Request-ID` because
   ingress-nginx forwards it — the client's, or one it minted — and prints
   it as the last field of its access log, so the ingress line and the
   application's lines join on the value unchanged, with no chart change.
   The proxy forwards whatever the client sent, so an inbound value is
   untrusted input on its way into every log line of the request: only a
   plain token of 8 to 64 characters from `[A-Za-z0-9._-]` is adopted,
   anything else is replaced and never echoed. The middleware is first on
   the global stack — health probes, PSP webhooks and the router's own 404s
   are requests too — so the first log line of a request already carries
   the ID.

3. **A queued job inherits the ID of the request that dispatched it.** That
   is the framework's own propagation, unchanged. A job that inherited none
   — dispatched by the scheduler, by a command, by another such job — and
   every artisan run mint their own: `Context::addIf` on `JobProcessing`
   and `CommandStarting`, after the framework's restore has run. Under
   `schedule:run` every scheduled task is a process of its own and
   therefore a unit of its own.

4. **The ID is on the page.** Shared as the Inertia prop `correlationId`,
   the same value as the response header, so it follows every visit and
   the feedback form can quote it. The framework's error pages print it as
   "Reference" through one published layout,
   `resources/views/errors/minimal.blade.php`, which every numbered error
   view extends: the page that failed hardest is the one a person most
   needs to describe.

5. **Not a trace.** No W3C `traceparent`, no spans, no Sentry tag. The ID
   is a join key for log lines and nothing more; distributed tracing would
   be its own decision.

## Consequences

- On the cluster every JSON record carries `extra.correlation_id`, and one
  query for the value returns the request and its jobs across pods.
  Locally the line formatter appends it as `{"correlation_id":"…"}`.
- The ingress log's `$req_id` is the application's ID. ingress-nginx's
  default log format prints it; a custom `log-format-upstream` must keep
  it, or the join in #157 loses its first hop.
- The brand still travels on its own (`PropagatesBrandContextToQueue`), not
  in `Context`: it carries a model with restore semantics `Context` does
  not offer, and folding the two together was not worth touching a
  mechanism that works.
- `resources/views/errors/minimal.blade.php` is the vendor file plus one
  line. A framework upgrade that changes the original owes a diff.
- A test that expects `CommandStarting` must bridge Symfony's console
  events itself — the kernel skips that bridge under the test runner
  (`.ai/rules/tests.md`). `CorrelationIdTest` shows how.
- Mail carries no ID header; the line the mailer job writes does. Finding
  a mail from the inbox side would be a later, small addition.
