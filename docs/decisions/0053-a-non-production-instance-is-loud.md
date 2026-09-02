# ADR-0053: A non-production instance is loud

**Status:** accepted

## Context

From Phase 3 on, a staging instance runs beside production (todos #53,
GitLab #68), and the import trial rehearses real customer data on it. Two
failures were waiting to happen, both silent: an organiser reads a staging
page and takes it for production, and a staging job mails real people —
order confirmations, verification links — from a rehearsal. LanCore had
neither guard and both happened.

Laravel's own default points the wrong way for this. `config/app.php` fell
back to `production` when `APP_ENV` was missing, so an instance that never
said what it was passed for production: no strictness, no marker, mail
flowing. The chart always sets `APP_ENV`, but the direction of the default
is the point — the failure that costs least is the one you notice.

## Decision

1. **Only the literal `production` is production.** `config('app.env')` is
   the single fact, read through `Konventix\Platform\Environment\DeploymentEnvironment`.
   Its default is now `undeclared`, not `production`: a missing or misspelt
   `APP_ENV` makes a non-production instance — strict models, the ribbon,
   the mail guard — never a quiet production one. Nothing else reads
   `env('APP_ENV')`; a second notion of "production" would let the two
   disagree.

2. **Every non-production page wears a corner ribbon**, rendered from the
   shared `environment` prop, null on production. Label and color are
   config (`ENVIRONMENT_RIBBON_LABEL`, `ENVIRONMENT_RIBBON_COLOR`), the
   label defaults to the environment name, and `ENVIRONMENT_RIBBON` forces
   it either way — off for a demo that must look real, on for a production
   drill. It is fully click-through: the brand topbar keeps its account
   menu and cart in that corner, so the release is a second line in the
   ribbon rather than a hover tooltip, which would need pointer events.

3. **Off production, mail only reaches `MAIL_ALLOWLIST`.** A listener on
   the mailer's own `MessageSending` event — the one door every sender
   uses, Fortify's notifications and Messaging's channel alike, in a queue
   worker as much as in a request — drops recipients outside the list and
   withholds the message when nobody is left, logging what it did. The
   list takes full addresses, `@domain` suffixes, and `*` for "everything",
   the honest setting behind a mailcatcher (Mailpit in Sail). Empty means
   nothing leaves: an instance that has not said who may be mailed mails
   no one.

4. **Off production, subjects carry `[<label>]`**, the ribbon's label, so a
   staging mail that does reach an allowlisted inbox says so in the list
   view. `MAIL_TAG_SUBJECTS=false` switches that off; the test suite does,
   to assert subjects verbatim, and opens the allowlist because the array
   transport delivers nothing anyway.

5. **The ticket-PDF watermark waits for ticket PDFs.** The same service is
   what a renderer will ask; the criterion stays open on #68 until there
   is something to watermark.

## Consequences

- The dev cluster runs as `staging` from 0.4.3 on, and therefore strict
  (`Model::shouldBeStrict`), like the test suite. A page that lazy-loads
  there is a bug found before production, not a reason to relax the mode.
- A deployment's mail configuration is no longer complete without
  `MAIL_ALLOWLIST` (`mail.allowlist` in the chart); the runbook and
  `.env.example` say so. Production needs none — the guard is inert there.
- `DeploymentEnvironment` is the place for every future "not on
  production" behaviour, and a sender that wants to bypass the guard has
  no hook to do it with; that is intentional.
- Configuration keyed by environment *name* needs an arm for the whole
  non-production family, not just `local`: Horizon's supervisor plan had
  `production` and `local` only, and the first `staging` day on dev ran no
  workers at all — no error, just queues nobody served (#160). It has a
  `*` arm now; anything similar (`environment('local')` switches) should
  ask `isProduction()` or name the family. Commerce's local checkout
  adapter stays `local`/`testing` on purpose: staging talks to a real PSP
  sandbox.
