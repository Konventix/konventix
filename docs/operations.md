# Operations

What an operator looks at and does when Konventix misbehaves. Alerts come
from `contrib/observability/alerts/konventix.rules.yaml` (ADR-0059); each
section below is the runbook anchor the rule's annotation names.

## Dashboards

Grafana folder "Konventix": `konventix-sale` (orders, payments, webhooks,
waiting room, messages, seating), `konventix-platform` (HTTP, queues, health,
runtime, database), `konventix-logs` (errors, request records, one
correlation id). Dashboards are generated from
`contrib/observability/src/`; an edit in Grafana is reverted on the next
resync.

## Finding a purchase

Every response carries `X-Request-ID`; error pages show it as "Reference";
a user can quote it. Paste it into the Logs dashboard's "Correlation id"
box. The panel shows the ingress access line (the id is its last field),
the `http.request` record with route, status and duration, every record
the request wrote, and the `queue.job` records of the jobs it dispatched,
in time order.

## Triggering each alert once

Acceptance for #168: every alert fired on purpose and received. Record
date, alert names and recipient under the alert's section when done.

- `kubectl -n konventix scale deploy konventix-horizon --replicas=0`, wait
  ten minutes: KonventixHealthCheckCritical (Horizon), KonventixRoleUnavailable,
  and once jobs queue up, KonventixQueueBacklog. Scale back to 1.
- `kubectl -n konventix scale deploy konventix-scheduler --replicas=0`,
  wait ten minutes: KonventixScheduleStale, then KonventixHealthCheckCritical
  (Schedule). Scale back.
- `kubectl -n konventix exec deploy/konventix-web -- php artisan tinker
  --execute 'Log::error("alert probe")'`: KonventixErrorsLogged within five
  minutes.
- KonventixDatabaseBackupMissing fires on its own on an instance that never
  completed a backup.

The other thirteen — KonventixMetricsDown, KonventixHealthCheckFailed,
KonventixQueueJobsFailing, KonventixWaitingRoomStuck, KonventixWebhookSilence,
KonventixPaymentFailureRate, KonventixHttp5xxRate, KonventixCheckoutSlow,
KonventixWalArchivingFailing, KonventixPodsRestarting,
KonventixResourceHoldSweepStale, KonventixClaimInviteMailFailing and
KonventixMailQuotaExhausted — are not triggered
by hand for #168; each has a `vmalert-tool` unit test in
`contrib/observability/alerts/tests/konventix.rules.test.yaml` that shapes an
input series so the rule's expression fires and asserts the labels vmalert
reports, which is the acceptance in place of a live trigger. Two of those thirteen
need more than the unit test to prove the *metric*, not just the rule, is
honest: KonventixWebhookSilence and KonventixPaymentFailureRate are proven by
running a sandbox payment flow — a Stripe test-mode webhook withheld, and a
run of declined test cards. KonventixWalArchivingFailing and
KonventixPodsRestarting are platform-side, sourced from CNPG's and kubelet's
own exporters rather than anything this application emits, so they are
observed when they occur in the cluster rather than provoked.

## A console page answers 403 to the operator admin

Capabilities are per scope: on the admin host only roles of the *operator*
scope count, and the one that exists is "Operator Admin". Since 0.6.3
`permissions:sync` (the migrate hook runs it on every deploy) re-grants that
role every capability the release declares, unless the operator has narrowed
it (`roles.customized_at`). On an instance where a page still 403s, run
`php artisan permissions:sync` once and reload; a role the operator narrowed
on purpose is reported as `skipped` and has to be widened by hand. Roles
granted on a *brand* do not apply on the admin host — that is ADR-0029, not
a bug.

## Closing sign-up

Operator settings in the console, "Let visitors create accounts": off, the
sign-up form and the Steam sign-up step send a stranger to the login page
with a note that sign-up is closed, and the login page drops its "Sign up"
link. Existing accounts log in as before. `SIGNUPS_ENABLED=false` in the environment (the chart's
`app.signups.enabled`) closes sign-up for the whole instance whatever the
switch says — the console then shows why beside the switch — which is what a
test system on a public host wants.

## Opening seating on an event

Order matters, because a registration only grows a seat claim at the moment
it is confirmed:

1. Create the seat categories under Categories in the operator console.
2. Give each category its own quota under Quotas, sized to the number of
   bookable places, and point the quota's Category field at it. Blocking a
   place or reserving one for the organisation then takes a unit off the
   sale the way a purchase does, and giving either back offers the unit to
   the waitlist.
3. Set the seating grant on every entry under Registration types that should
   come with a place, and pick the category it grants.
4. Import or draw the hall plan under Seating plans and publish it.

Unpublishing a plan to edit it does not stop the category selling. The quota
is untouched, registrations still sell and confirm, and their seat claims wait
`unplaced` until the plan is published again, at which point the picker serves
them.

**Cutover hand-over (Eventula import):** step 3 has to happen before the
Eventula import runs. The importer carries users, events and orders only --
it does not carry seating -- and a registration that was already confirmed
when its type had no seating grant gets no claim, so those participants never
see the picker. There is no backfill command: a claim is only created when a
registration is confirmed or amended, so an already-confirmed registration on
a type that gained the grant afterwards needs an amendment adding that grant.
The amendment id keys the claim, so replaying the same amendment adds
nothing.

## Handing a participant their ticket

A ticket is self-service: the buyer downloads every admission their
registration granted from `/my/registrations/{registration}/ticket`, and
anyone who has claimed an admission downloads their own from `/my/tickets`.
There is no admin route that generates a participant's ticket on an
operator's behalf — that document is deliberately participant-facing only
(ADR-0061). When someone at a desk cannot self-serve (no account, a device
that will not open the mail), the operator equivalent is the **badge**, not
the ticket: `GET /admin/admissions/{admission}/badge` (`BadgeController`,
behind `admission.print-badge`) prints the physical identity document
check-in already reads, from the same KVX1 token. Sending someone to the
door with a printed badge and no ticket is normal; the two documents carry
the same scannable code.

An admission with no linked holder account (`holder_user_id` is null) has no
row in anyone's `/my/tickets` — group and B2B bookings look like this until
a claim link is used. The buyer's own
`/my/registrations/{registration}/ticket` still lists every admission the
registration granted, holder or not, so a buyer can always retrieve every
ticket their registration paid for, claimed or otherwise.

### The brand's entrance signing key

Every KVX1 code — on a ticket, on a badge, in the entrance API — is signed
with the brand's own Ed25519 keypair, and a door verifies it against the
published public keys without asking the server anything (ADR-0033). The
key is per brand, not per event: one `entrance_signing_keys` row that can
still sign is all a brand ever needs.

**While a brand has none, it cannot issue a single code.**
`IssueAdmissionToken` refuses to sign rather than mint something a door
would reject, so every ticket download, every badge print and every ticket
page for that brand answers 500 with `Brand [...] has no active entrance
signing key`. This is not a slow degradation an operator notices on the
dashboards; it is the buyer's first ticket download failing.

A brand created in the console gets its key the moment it is created
(Platform raises `BrandCreated`, Admission mints on it). A brand that came
from `konventix:install`, from a seeder, or from before this shipped gets
one on the deploy path: `konventix:migrate` — the migrate Job, behind the
same `AUTO_MIGRATE` gate as the migrations and `permissions:sync` — ends
with

```
vendor/bin/sail artisan admission:rotate-signing-key --all
```

which mints **only** for brands that have no key able to sign and reports
how many it touched. It is safe on every release and safe to run by hand at
any time.

**Rotating one brand** is the other mode, and a deliberate act:

```
vendor/bin/sail artisan admission:rotate-signing-key <slug-or-ulid>
```

It always mints a new key and retires the brand's previous ones. Rotate
when a private key may have leaked — a copied database dump, a shared
`.env`, a disposed server — and otherwise leave it alone; there is no
scheduled rotation and none is owed, because the keys never leave the
application's own database and are encrypted at rest. **Retiring is not
deleting.** A retired key stops signing immediately but keeps *verifying*
until its `expires_at`, which is a month past a token's own lifetime, so
every QR already in an attendee's wallet keeps working and no ticket needs
reissuing. Nothing about rotation is visible to a participant.

After rotating: nothing else to do. Doors refresh the published key set
from `/api/entrance/signing-keys` on their next sync, and a door that has
not synced yet still verifies the codes it is shown, because the key that
signed them is retired rather than gone.

### A superseded-nonce refusal at the door

`CheckInRejectionReason::NonceSuperseded` means the presented code is real
but is not the admission's *current* one — someone is scanning an older
printout or QR after the admission's token was rotated
(`IssueAdmissionToken::rotate()`, run only on a deliberate reissue, never on
an ordinary download). The fix is always the same: the holder needs their
current ticket, downloaded fresh from `/my/tickets` or reprinted as a badge
from the console. This is not paged — the observability task deliberately
adds no alert for it (ADR-0059 point 8), because a refusal is already
visible to the person standing at the door — but
`admission_tokens_superseded_total` on the Sale dashboard's "Tickets and
calendars" row is what an operator watches during a sale: a steady trickle
is reissues behaving as designed, a spike against one event is worth asking
about.

### Backfilling the ticket's read models

Two commands, safe to re-run, neither one specific to a single event:

- `vendor/bin/sail artisan registration:announce-ownership` — re-emits the
  narrow `RegistrationOwnershipAnnounced` event for every existing
  registration, so `admissions.buyer_user_id` is populated for admissions
  issued before this feature shipped. Never re-sends a confirmation mail:
  that is exactly why this is its own event and not a replay of
  `RegistrationConfirmed`.
- `vendor/bin/sail artisan event:replay-details` — re-emits
  `EventRescheduled`, `EventDetailsUpdated` and, since this feature,
  `VenueAssigned` for each event's primary venue, so Admission's
  `admission_event_summaries` and Registration's
  `registration_event_summaries` both pick up a name, a schedule and a
  venue for events created before the two read models existed. Run it again
  after backfilling a venue onto an event that predates this deploy — the
  command does not run itself. Since #292 the same run also carries each
  event's `banner_media_id` into both summaries.
  Since 0.9.0 the run also re-emits `EventDaysChanged` with each event's
  current day set, which fills Admission's `entrance_event_profiles.days`
  and Agenda's `agenda_event_summaries.days` for events that predate those
  read models; and it takes `--with-state`, which re-emits each event's
  current lifecycle event — the extra step Agenda's programme needs, and
  nothing the two ticket summaries here care about. See **Programme**.
- `vendor/bin/sail artisan admission:announce-ownership` — re-emits the
  narrow `AdmissionOwnershipAnnounced` event for every admission (#292), so
  Resources' `resource_admissions` read model — who holds and who bought
  each ticket, which the per-ticket seat picker authorises on — is
  populated for admissions issued before that table existed. Only a
  projection listens; never a replay of `AdmissionIssued`, which would
  count every admission on the dashboards a second time. Run it once after
  the deploy that brings the table, after `event:replay-details`.

For an event created before `entrance_event_profiles` shipped
(`2026_08_12_170000`), "fills `entrance_event_profiles.days`" understates
what `event:replay-details` does: such an event has no profile row at all —
`CreateEvent` emits `EventReentryPolicyChanged` at creation, so every event
created since that migration already has one, and the replay only completes
its `days` column — and for it the replay's `EventDaysChanged` **creates**
the row. If that event also has `event_days` rows, the new profile changes
what the door does in both directions: it newly rejects a scan on a date
outside the event's calendar as `OutsideEventDay`, where it was silently
accepted before, and it fixes the opposite bug — every day-scoped ticket at
that event had been rejected `NotValidOnThisDay`, because a missing profile
always resolved to no day. Both are D-ADM-04's intended semantics, not a
regression, but they are a door-behaviour change nonetheless. Run
`event:replay-details` before an event's doors open, never during one, for
any event old enough to be missing this row.

### Backfilling the media library's variants — the post-deploy step for 0.7.0

A variant is cut when a picture is uploaded, so every picture already in a
brand's library predates any variant invented afterwards. `ticket_banner`
(0.7.0) is the first such case: without it the ticket PDF falls back to the
original and base64-inlines the whole picture on every page, which is what
made a multi-page ticket slow. Run it once after the 0.7.0 deploy:

```
vendor/bin/sail artisan media:regenerate-variants
```

It walks the library across every brand — a console run resolves no brand
(ADR-0029), so the walk deliberately escapes the brand scope and rewrites
each row in the brand it already belongs to; it creates nothing. It is safe
to re-run: by default only the variants an item is actually missing are
cut, and a row whose map does not change is not touched at all, so a second
pass reports "Nothing to backfill" and writes no bytes. A picture too small
for a variant gets none and is skipped on every later pass too —
`ThumbnailGenerator` never upscales, and the consumers fall back to the
original.

`--brand=<ulid|slug>` narrows the walk to one brand, which is how to spread
a large library over several runs. `--only=thumb,ticket_banner` narrows it
to named variants — the flag is validated against what the generator knows,
so a typo stops the run instead of quietly backfilling nothing.
`--missing=false` re-cuts variants an item already has; that is a rewrite,
not a backfill, and it invalidates whatever a reader has cached, so it is
for a changed generator and nothing else.

## Live hub

While an event runs, a participant who has been let in at the door gets a
**pill in the topbar** and a page at `/live` — their ticket, the event's
announcements, their seat and the help dialog (design
`docs/superpowers/specs/2026-09-22-event-hub-design.md`). Access is presence,
not a permission: nobody grants it and nobody has to revoke it. It appears on
the first accepted `in` scan and goes away on its own.

Two things run it. **Admission** owns who is inside: an accepted scan writes
`admission_event_presence` and emits `ParticipantAdmitted`; the event ending,
the admission being revoked, or a once-per-day window passing emits
`ParticipantHubAccessEnded`. **Platform** owns the shell: it projects those
events into `platform_hub_access`, answers "which event is this person in"
from one cache read, and renders the page.

**The sweep.** `admission:close-presence-windows` runs **every minute**
(`withoutOverlapping()`), and it is the only thing that notices a
`once_per_day` window ending — that hour arrives by the clock, not by anything
anybody emits. If the scheduler role is down, participants of a once-per-day
event keep the hub past the day's end; nothing else breaks, and the next run
catches up (the command only touches rows that still have access, so a late
run ends each one once). The day's end is the event day's **`day_ends_at`**
(`HH:MM`, set in the days editor, empty meaning 00:00 of the following day) —
a LAN day that runs until 03:00 belongs in that field, or the hub goes dark at
midnight while the hall is still full. Events with re-entry `once_only` or
`unlimited` have no window at all and stay open until the event is concluded
or cancelled.

**Rolling the hub out while an event runs.** `admission_event_presence` only
starts filling from the moment 0.9.0 is deployed, and a pill is written by a
*scan*, not by a ticket — so a release that lands on the Saturday leaves
everybody already inside the hall without a hub, and on an `unlimited`
re-entry event they may never scan again to get one. Seed them from the scans
that already happened, once, per running event:

```
vendor/bin/sail artisan admission:replay-presence --event=EVENT_ULID --seed
```

It walks that event's **accepted** check-ins oldest first through the same
projection a live scan uses, so the rows, the day windows and the
`ParticipantAdmitted` events come out identical; a holder whose last scan was
`out` gets the hub with a grey dot, not a green one. Rejected scans and scans
that never resolved to an admission are skipped, as they were on the day. Safe
to run twice — every consumer upserts — and safe to run while the event is
live.

One thing it does that is **not** scoped by `--event` and not scoped by brand:
it finishes with one run of the window sweep, the same pass
`admission:close-presence-windows` makes every minute, so a row it rebuilt from
an earlier day does not sit there claiming access it has already lost. That
pass ends every expired window **on the deployment** and emits
`ParticipantHubAccessEnded` for each, across all brands — which is what the
scheduler would have done a minute later anyway, and is a no-op for rows
already closed. It prints no count of its own; the seed line counts scans, not
endings. If the scheduler role is healthy there is nothing here to think
about; if it has been down, expect the sweep's backlog to clear on this run.

**A participant was scanned in and has no pill.** Work down, in this order:

1. **Is the queue running?** Nothing about the hub happens on the door's own
   request: the presence projection is a queued listener that runs after the
   scan's transaction commits, deliberately, so the door can never wait on the
   hub or fail over it. A stopped or backed-up worker delays every pill by
   exactly the length of the backlog and leaves both tables below empty
   (Horizon; `KonventixQueueBacklog`).
2. **Is there a presence row?** `admission_event_presence` for that event and
   user. Queue healthy, scan accepted and still no row means the admission has
   **no holder account** (`admissions.holder_user_id` is null) — a claim link
   nobody used. There is nothing to log in to, so there is deliberately no row
   and no error; the fix is the claim, not the hub. A rejected scan writes
   nothing either.
3. **Row there, `hub_access = false`?** `access_ended_reason` says which of the
   four doors it left through: `day_ended` (the window passed — the next
   morning's scan re-opens it), `concluded`/`cancelled` (the event did), or
   `revoked` (the admission that let them in was revoked, and no other
   admission of theirs at that event survived).
4. **Row there with access, but no `platform_hub_access` row?** The two are
   written in the same job — Admission writes its row and emits
   `ParticipantAdmitted`, Platform's listener projects it there and then — so
   a presence row without a hub-access row means Platform's listener threw.
   The log has it; the job will have been retried up to three times. That is
   what the replay is for — it re-emits `ParticipantAdmitted` and
   `ParticipantPresenceChanged` from the presence rows that still have access,
   so Platform's side is rebuilt from the contract and not by hand:

   ```
   vendor/bin/sail artisan admission:replay-presence --event=EVENT_ULID
   ```

   Without `--event` it replays every admitted row on the deployment, which is
   the blunt form of the same repair. Idempotent either way.
5. **Both tables right, still no pill?** It is the cache. Platform answers the
   shell from **`hub-access:{brand}:{user}`** (TTL 600s, the brand's and the
   user's ULIDs). All four Admission participant events forget that key, so a stale
   answer should be impossible — but forgetting it by hand is harmless and
   settles the question:
   `vendor/bin/sail artisan tinker --execute 'Cache::forget("hub-access:BRAND_ULID:USER_ULID");'`

**A pill that will not go away.** The inverse, and the one worth knowing by
name: the event is over — or the admission was revoked, or the day ended — and
the participant still has the pill and still reaches `/live`.

1. **Is Admission's row closed?** `admission_event_presence` for that event and
   user. `hub_access = false` there but a `platform_hub_access` row still
   standing means the ending was decided and never announced, or announced and
   never projected: Admission's side is right and Platform's is stale.
2. **Both rows still open?** Then nothing decided the ending. For a
   `once_per_day` window that is the scheduler (see **The sweep** above); for a
   concluded or cancelled event it is the queued listener on Event's own event,
   the same chain as step 1 of the previous list.
3. **The repair either way is the projection, not the row.** Deciding an ending
   twice is safe — the presence row carries what has actually been announced,
   so a second pass emits once and only for what is genuinely outstanding:

   ```
   vendor/bin/sail artisan admission:close-presence-windows
   ```

   for a window that should have closed, and for anything else the event's own
   close path. Do **not** delete the `platform_hub_access` row by hand; it comes
   back on the next admission and the presence row still says access was never
   ended.
4. **Green dot on somebody who left?** That is `on_site`, a badge and never a
   gate. `ParticipantAdmitted` asserts it true by definition and the presence
   event that follows corrects it, so a redelivered admission can flip it back
   for as long as it takes the next presence event to arrive. A check-out scan,
   or `admission:replay-presence --event=EVENT_ULID`, settles it.

**A layout change did not apply.** The operator's composition per event —
card order, hidden cards, and the "Startseite wird zum Hub" switch — is saved
in Event (`admin/events/{event}/hub`, capability `event.update-event`) and
projected into Platform's `platform_hub_layouts` on `EventHubLayoutChanged`.
The page reads it through **`hub-layout:{event}`** (TTL 600s), forgotten on
every save. A card that is registered but not in a saved layout is appended
and visible, so a newly deployed module's card appears without anyone
touching the page; a key in a layout that no module registers any more is
dropped silently.

**The home switch.** With "Startseite wird zum Hub" on, a signed-in admitted
member's `/` *is* the hub. Guests and members nobody admitted see the ordinary
home page and pay no hub query for it, and `/home` always serves the plain
brand home — that is the URL to hand someone who needs to see the public page
while the switch is on.

**What to watch.** `konventix_platform_hub_views_total{brand}` counts hub
renders (both routes) — on event day it should move; flat while people are
being scanned in points at the chain above.
`konventix_platform_hub_card_failures_total{brand,module}` counts
contributions whose payload threw and were dropped: the page still renders,
minus that card, and the module label says whose it was. Anything above zero
means a participant is looking at a gap — the matching `Log::error` names the
card key, and `KonventixErrorsLogged` is what pages for it. Admission's side
is `konventix_admission_hub_access_total{brand,outcome}`, with `outcome`
`admitted` or one of the four end reasons.

## Programme

The event's timetable (`app-modules/agenda`, design
`docs/superpowers/specs/2026-09-23-timetable-design.md`). An organizer
composes it in the console at `admin/events/{event}/agenda` with the
capability `agenda.manage-agenda`; everybody else reads it at
`/events/{slug}/programm`, with no account and no ticket. The module is
gateable, so a brand that has not switched `agenda` on has no programme at
all: no public page, no editor, no hub card, and nothing logged about any of
them.

**The public page holds no cache and is brand-scoped.** Every request builds
the grid from `agenda_items` for the brand the host resolved to, so a change
in the editor is on the page on the next reload — there is no cache to bust
and no warm-up step after a deploy. The flip side is that it is a database
read per view; it is a plain indexed query per event day and has no fan-out,
but it is worth knowing before a link goes into a newsletter.

**What to watch.** `konventix_agenda_items_published_total{brand}` on the
Platform dashboard ("Programme items published") counts items that became
publicly visible — one increment per item, never for a re-publish, so it is
items and not button presses. There is **no alert on it, on purpose**: a
programme that is empty, or that has not moved for weeks, is a brand still
planning, which is most of an event's life. An empty programme is not an
incident, and the page renders an empty state rather than an error. What
*would* be an incident shows up elsewhere: a throwing hub card is
`konventix_platform_hub_card_failures_total{module="agenda"}` (see **Live
hub**), and a 500 on the public page is `KonventixHttp5xxRate`.

### Turning the programme on for an event that already exists

Agenda keeps its own read model of each event — `agenda_event_summaries`,
holding the slug the public URL resolves by, the name, the timezone, the day
set and the **lifecycle state** — projected from Event's contract events
(ADR-0004: Agenda may not read Event's tables). An event that was created
before this module shipped has emitted none of those since, so after the
deploy its row is missing, and a row built by a replay that carries no day
set has `days` `NULL` while its `state` is `null` too. Both gaps are
visible, and they look nothing alike. A `null` state is "nobody has told us
yet", which the public page refuses: the editor works, the organizer
publishes items, and `/events/{slug}/programm` answers **404** for a
published event whose programme plainly exists. A `NULL` day set is worse to
diagnose, because the page answers **200** — the programme is built day by
day, so with no days there are no rows, and the reader gets "Für diese
Veranstaltung ist noch kein Programm veröffentlicht." over a programme in
which every item is published. Either stays that way until somebody touches
the event again in Event's console.

So after activating `agenda` for a brand whose events predate it, run:

```
vendor/bin/sail artisan event:replay-details --with-state
```

That run backfills the slug, the name, the timezone, the venue **and the day
set**: `EventDaysChanged` is re-emitted for every event from its current
`event_days` rows, with no flag, because it carries the complete current set
rather than a transition — the same reason the schedule and the details need
none. Admission's `entrance_event_profiles.days` is filled by the same
event, so a door of a pre-existing event gets its day calendar out of this
run too, and an event with no days emits the empty set, which is its true
current value.

`--with-state` is what the *state* needs, and it is the flag that closes the
404: it re-emits the **one** lifecycle event that says what state each event
is in now — `EventAnnounced`, `EventPublished`, `EventStarted`,
`EventConcluded`, `EventCancelled` or `EventArchived` — never the path that
led there, and nothing at all for a draft, which has no lifecycle event and
needs none. The stored timestamps (published, cancelled, concluded,
archived) are replayed exactly; `Announced` and `Running` keep no timestamp
column anywhere, so those two fall back to the row's `updated_at`, which no
consumer reads for anything but display.

`EventDaysChanged` was held to the same standard before it joined the
unflagged three: it has exactly two registered listeners — Agenda's
`ProjectEventSummary::handleDaysChanged()` and Admission's
`ProjectEventDaysOnEventDaysChanged` → `ProjectEntranceEventProfile::days()`
— and both replace their own copy of the day set outright, so a second pass
writes the same row. It implements no `AuditableEvent`, so Compliance's
wildcard subscriber ignores it, and no metric is recorded on it. **A new
listener on `EventDaysChanged` owes the same check**, exactly as one on the
seven lifecycle events does.

Safe to re-run, and safe with the flag: every listener registered on those
seven events was checked for idempotency before the option existed —
Agenda's and Registration's projections (the latter only fills
`public_since` `whereNull`), Compliance's `StartRetentionClock`
(`updateOrCreate` on the event id, with the event's own `concluded_at`), and
Admission's `EndHubAccessOnEventClosed`, whose sweep skips every presence
row that is already settled and therefore emits nothing on a second pass,
and Community's `CloseRoomOnEventClosed` (added with the shoutbox, on
`EventConcluded` and `EventCancelled`), which closes only a room whose
`closed_at` is still empty, from the event's own `concluded_at` or
`cancelled_at`, so a replay never moves the retention clock. Compliance's wildcard audit listener ignores all seven: none of them
implements `AuditableEvent`. **Adding a listener to one of those seven
events puts it on this list** — if it is not idempotent, this flag stops
being safe and the note belongs here.

Without the flag the command touches no lifecycle state and is still the
right one for the ticket read models (see **Backfilling the ticket's read
models**), which now get the day set out of the same run.

## Shoutbox

The event's wall (`app-modules/community`, design
`docs/superpowers/specs/2026-09-23-shoutbox-design.md`, ADR-0067). Admitted
participants read and post at `/live/shoutbox` and on the hub card; an
organizer with `community.moderate-community` works the desk at
`admin/events/{event}/shoutbox`. The module is gateable: a brand with
`community` off has no wall, no card, no desk, and none of Community's
listeners do anything for it.

**Retention: 30 days after the event concluded** (D-COM-05).
`EventConcluded` sets the room's `closed_at` to the conclusion instant —
and `EventCancelled` to the cancellation instant, since a cancelled event
never concludes; the wall refuses posts from then on. It is **not** left
readable for participants: the same events end their hub access
(Admission's queued `EndHubAccessOnEventClosed`), and `/live/shoutbox`
finds its room through the member's *running* event, so once that listener
has run, the page and its feed answer 404 to every participant and the hub
card is gone. Only the desk still reads a closed wall.
`community:sweep-shoutbox` runs **daily** (`withoutOverlapping()`) and, for every room closed at least
30 days ago, deletes its messages, the reports on them and its mutes. The
room row stays as a tombstone, so the desk URL still opens onto an empty,
closed room rather than a 404. No participant URL names a room, so for
participants nothing changes at the sweep: the wall was already gone. The
sweep runs across every brand and is not gated on `community`: switching
the module off does not stop a closed wall's clock. It is safe to run by hand at any time (`php artisan community:sweep-shoutbox`
in the scheduler or web pod); a room already swept has nothing left to
delete. If the scheduler role was down for days, the next run catches up —
there is no window it can miss.

One gap to know: a room is closed only by `EventConcluded` or
`EventCancelled` *while `community` is on for the brand*. A brand that
switches the module off between opening its wall and closing the event
leaves that room open, and the sweep never reaches it. `event:replay-details
--with-state` is **not** the repair: it re-emits only the event's *current*
lifecycle event, which for an archived event is `EventArchived`. Set the
room's clock by hand from the event's own instant instead:

```
kubectl -n konventix exec deploy/konventix-web -- php artisan tinker --execute '$e = Konventix\Event\Models\Event::withoutBrandScope()->findOrFail("EVENT_ULID"); echo Konventix\Community\Models\Room::withoutBrandScope()->where("subject_type", "event")->where("subject_id", $e->id)->whereNull("closed_at")->update(["closed_at" => $e->concluded_at ?? $e->cancelled_at]);'
```

It prints the number of rooms it closed (0 or 1), touches a room only while
its `closed_at` is empty, and leaves it empty for an event that has neither
concluded nor been cancelled. The next daily sweep deletes the room's
contents once 30 days have passed since that instant — at once, if they
already have.

**GDPR.** Community's handler exports the subject's own lines (removed ones
too), the reports they filed, the mutes held against them and their
admission badges. Erasure deletes their lines outright together with the
reports on them, anonymises the reports they filed (reporter and note
nulled, the report kept), nulls their ULID wherever they acted as a
moderator on somebody else's row (who removed a line, locked a room, placed
a mute, resolved a report), and deletes their mutes and badges. Online and
away are never on disk, so there is nothing to erase for them.

**What to watch** (Platform dashboard, row "Shoutbox").
`konventix_community_messages_posted_total{brand}` counts lines accepted.
`konventix_community_shoutbox_broadcasts_total{brand,outcome}` counts the
live push of a line or a removal to the room, `sent` or `failed`. A
removal made at the desk is pushed with the room brand's Reverb
credentials, not the admin host's (which has none). A failed push never
fails the post or the removal — the change is already saved — but an open
wall does not see it until it is reloaded. A failed push does not make a
client reconnect, so the catch-up a reconnect runs only helps when the
outage also dropped that client's socket. Until then a removed line stays
visible on every open wall. This counter and
`KonventixShoutboxBroadcastFailing` are the only places a Reverb outage on
the wall shows. The matching exception is reported, so the Logs dashboard
has its message.

## The brand's Reverb credentials

Realtime — the waiting room's ticks, the notification bell, the shoutbox —
runs over one shared Reverb server with **one application per brand**
(ADR-0025). The brand's ULID is the application id, and the
`reverb_app_key` / `reverb_app_secret` columns on its row sign
subscriptions. A client holding one brand's key cannot subscribe to
another brand's channels: the isolation is by credential, not by channel
name and not by process.

**Nothing has to be provisioned.** The brand model mints a key and a secret
when the row is created, and `konventix:install` fills them in for a brand
that predates the columns. `REVERB_APP_KEY` in the deployment's ConfigMap
is empty **on purpose** and must stay that way: with
`REVERB_APP_PROVIDER=brands` the applications come from the rows, and the
variable is shipped present-and-empty only because `routes/channels.php`
builds a Pusher client at boot with string-typed parameters, so an absent
variable is a fatal `TypeError` on every artisan command. Setting it would
not help a brand; it belongs to the `config` provider, which this
deployment does not use (#224).

**What a missing key looks like.** The brand's pages log
`You must pass your app key when you instantiate Pusher` in the browser
console and no realtime feature works, while everything else on the page is
fine. `HandleInertiaRequests` passes the current brand's key to the page as
`reverb.key`, so an empty column reaches the browser as an empty key.
Confirm with a websocket handshake against
`https://<brand-host>/ws/app/<key>`: a healthy application answers
`pusher:connection_established`.

**Rotating one brand's credentials** is a deliberate act for a leaked
secret, not a schedule. There is no rotate command today: write new random
40-character values into the brand's two columns, and every currently
connected client of that brand drops and reconnects with the key its next
page load carries. Do it when the brand is quiet, and never in the middle
of a sale — the waiting room's ticks are what tell a queued buyer their
turn has come. A command that does this with a warning and a confirmation
is worth having before the first production rotation; it does not exist
yet.

## Mail quota

The default mail transport sits behind a mailbox provider that refuses
mail past a fixed number of recipients per hour and per day — netcup's
webhosting allows 100 an hour and 500 a day (forum.netcup.de, read
2026-09-21) — and a burst used to hit that wall on the transport and fail.
Since the 2026-09-21 bugfix round Messaging counts what it sends per
budget and window (`Mail\MailQuota`, `config/messaging.php`,
`MAIL_QUOTA_PER_HOUR` / `MAIL_QUOTA_PER_DAY`) and holds a non-urgent mail
that would cross either limit: the delivery log shows it `held` with
`hold_reason = mail_quota` and a `hold_until` at the start of the next
free window — the next UTC clock hour, or the budget's next midnight —
and the every-minute `messaging:flush-held` sweep releases it, checking
the quota again as it does so an exhausted window re-holds instead of
bursting. Nothing is dropped; in-app and push rows are not mail and never
wait; an urgent send (a console resend, an urgent announcement) is never
held but still counts.

Budgets follow the account the provider is counting: a brand with its own
SMTP transport has its own hourly and daily count, keyed by its brand id;
every brand on the application's default mailer shares one (`default`).
Per-brand overrides live on the console's mail-settings page ("Sending
quota"; blank keeps the configured default) and the index shows the
effective pair per brand. The sale dashboard's Messages row shows the
share of each window already used and the mails held per hour;
`KonventixMailQuotaExhausted` fires when a window is full.

A budget's day is a day in the budget's own timezone, and since 2026-09-21
(Round 4, D-MSG-10) that is the brand's mail-settings timezone only for a
brand with its own transport. The shared `default` budget rolls over at
**UTC midnight** whatever timezone the brands on it have set: the provider
counts the one mailbox account's day once, and keying it per brand let two
brands in different zones spend overlapping days against the same 500 —
and let a hold taken by one of them reopen at a midnight the counter
behind it had never reached. Setting a brand's timezone still moves its
quiet hours and its own day; it moves the daily mail budget only once the
brand has its own SMTP transport.

## Support requests

Since Round 4 (#88, #304, ADR-0063) a visitor can say that something is
wrong from any page, and the operator console can too. The report is a
`support_requests` row: what the person typed, one or more of the five
categories (*purchase affected*, *not visible*, *does not work*, *looks
wrong*, *suggestion*), and the technical context they could not be expected
to quote — **both correlation ids**, the route, the release, the brand, the
previous page and the last five visits, the viewport, and the last
JavaScript error the page produced. No IP address is stored anywhere, no
cookies, no form contents of the page being reported, no screenshot; the
dialog lists what goes along before it is sent.

The two correlation ids are the point of the whole feature. `pageCorrelationId`
belongs to the request that misbehaved and is what to paste into the Logs
dashboard; `submitCorrelationId` belongs to the POST that filed the report.
A report therefore arrives already carrying the key to its own log lines
(ADR-0056).

**Where a report lives.** `filed_on_brand_id` records which brand host it
came in on and is *provenance, not a scope*: the console inbox lists across
every brand, and a report filed on the operator console has no brand at all
— it appears under `console` on the dashboards and in the alert
annotations. `support.view-requests` sees the inbox,
`support.triage-requests` moves it, and `support.view-reporter-contact` is
needed to see an unmasked address; every unmasked view is in the audit
trail. `permissions:sync` materializes the **Support Desk** role for every
brand — Support is always on and cannot be switched off per brand
(ADR-0063).

**States.** `received` → `seen` (set automatically the first time somebody
opens it in the console) → `in_progress` → `resolved` or `wont_fix`, with a
reopen from either terminal state back to `in_progress`. Only a terminal
state sets `closed_at`, and `closed_at` is the only retention clock.

**Retention.** `support:purge-requests` runs daily at 03:20 and deletes
closed reports — with their threads — once
`support.retention.days` (`SUPPORT_RETENTION_DAYS`, 180 by default) has
passed since they closed. An **open** report is never purged however old it
is: its age is a triage problem, not a retention one, and
`KonventixSupportUnseen` is what reports it. A window of zero or less is
refused by the sweep rather than obeyed. Compliance's retention-rules UI is
deliberately not wired to this yet; the window is config.

**Privacy.** A subject request reaches Support's own GDPR handler, which
answers for an account **and** for a bare e-mail address — a guest report
is personal data about somebody who never had an account. Erasure nulls the
account id and the address, replaces what the person wrote (in the report
and in their own replies) with `[removed on request]`, and drops the user
agent, the viewport, the visit buffer and the captured client error. The
row itself stays: it is the operator's record that something was broken on
a given release, and what is left says nothing about who reported it.

### Reporter access

A signed-in reporter follows their own report under **My support requests**
in the account menu; nothing to operate there, and no capability — the page
answers 404 for anything the signed-in account did not file. A guest follows
theirs through a **signed link valid 30 days**, mailed in the receipt. Both
see the report, the technical context and the **external** thread only; an
internal note never leaves the console, and a reporter's reply arrives as an
external message that notifies the brand's triage holders exactly like a new
report does.

Mails after the receipt are gated on `contact_consent` — the "you may
contact me" checkbox. Without it, state changes and replies are readable on
the page but are never sent. The console's reporter card prints which way
that went, so an operator knows before they write whether anybody will be
told.

**"My link does not work."** That is normal after 30 days and after a mail
client has wrapped the URL. The page a dead link lands on is not a 403: it
is a form with one field, the eight-character reference, and one button.
Pressing it mails a fresh 30-day link **to the address stored on the
report** — never to an address typed into the form, which is why there is no
address field and why nobody needs to be talked through one. Tell the person
to press the button; nothing has to be done in the console.

It answers the same sentence whether the reference exists, never existed, was
purged by the retention sweep, or belongs to an account rather than a guest.
So "they pressed it and got the confirmation" is **not** evidence that a mail
went out. If one really did not arrive, check `/admin/messages` for a
`support.request-link` send to that report's address, and the suppression
list for that address.

**The throttle** is three re-issues an hour, keyed on the **reference**, not
on the caller — the inbox is what is being protected. A fourth press inside
the hour answers 429 and the page says so. There is no override: the person
waits, or an operator reads the answer out of the console and replies in the
thread, which reaches them on the same address without a link at all. The
limiter is `support-link`, declared in `SupportServiceProvider`; the report
form's own limiter, `support-report`, is a different budget.

Nothing else revokes a link inside its 30 days, and a link grants read and
reply on **one** report. Once the retention sweep has purged a report, its
link answers 404 and a re-issue sends nothing.

## Alerts

### KonventixMetricsDown

The scraper got no answer from `/metrics` for five minutes. Either every
web pod is down (KonventixRoleUnavailable fires too) or the secret the
scraper presents no longer matches `HEALTH_ENDPOINT_SECRET`. Check
`kubectl -n konventix get pods`, then the vmagent log for `konventix`:
404 means a wrong or missing secret — `VerifyHealthSecret` answers a
missing route on purpose rather than confirming the endpoint exists to a
caller who cannot open it (ADR-0029) — connection refused or a timeout
means the NetworkPolicy.

### KonventixHealthCheckCritical

One of Database, Redis, Horizon, Schedule or WaitingRoom has reported
failed or crashed for five minutes. Open `admin/health` on the admin host
for the check's message. Database and Redis: the backing service; Horizon:
the horizon deployment; Schedule: the scheduler deployment; WaitingRoom:
the waiting-room supervisor is not draining (KonventixWaitingRoomStuck
says the same from the metric side).

### KonventixHealthCheckFailed

A non-critical check (Cache, DebugMode, UsedDiskSpace) has failed for
fifteen minutes. DebugMode failed means `APP_DEBUG=true` on a
non-local instance: fix the ConfigMap now. UsedDiskSpace: the storage
volume; see which path `HEALTH_DISK_CHECK_PATH` names.

### KonventixQueueJobsFailing

A job exhausted its attempts in the last five minutes. The summary names
the class and queue. The Logs dashboard "Failed jobs by class" panel and
the `queue.job` record with `outcome=failed` carry the exception. Horizon
under `admin/horizon` lets you retry once the cause is fixed.

### KonventixQueueBacklog

The oldest pending job on a queue has waited over a minute for ten
minutes. Either workers are down (see Horizon processes per queue) or a
job is slow. `messaging` backlog during a sale is mail volume and can
wait; `waiting-room` backlog means visitors are not being admitted.

### KonventixWaitingRoomStuck

Tickets are queued but no admission run happened for three minutes. The
`waiting-room` supervisor is not running its tick: check the horizon
deployment and `admin/horizon`; the scheduler must also be up, it
dispatches the batches. Visitors on the holding page see no progress
while this fires.

### KonventixWebhookSilence

Payments are pending and the named provider has sent no webhook for
fifteen minutes. Check the provider's dashboard for webhook delivery
errors, the ingress log for `api/commerce/webhooks/<provider>` with a
non-2xx status, and that the webhook URL and signing secret in the
settlement console are current. `commerce:reconcile-payments --pending`
settles what the provider knows about meanwhile.

If the Orders list shows nothing pending for the brand the metric names,
compare `konventix_payments_pending` on `/metrics` with
`select brand_id, count(*) from orders where status = 'pending' group by 1`.
Before 0.6.2 the gauge only wrote rows for brands with a pending order, and a
row once written stayed in Redis at its last value after the order was
cancelled — dev carried `payments_pending{brand="sxlan"} 1` for two days
against an empty table (2026-09-13). Since 0.6.2 every brand is written on
every scrape, `0` included. A stale row from an older release goes away with
`valkey-cli --scan --pattern 'konventix:metrics:*payments_pending*' | xargs
valkey-cli del` on the app's Redis; the next scrape rewrites it.

### KonventixPaymentFailureRate

More than 30 percent of at least ten payments failed in ten minutes at
one provider. Look at the Sale dashboard "Payments per minute by provider
and outcome" and the provider's dashboard for a declined-reason pattern;
a card-network outage looks exactly like this. Nothing to fix in the
application unless the failures carry an application error.

### KonventixHttp5xxRate

Over five percent of at least twenty requests answered 5xx for five
minutes. The Platform dashboard "5xx per minute by route" names the
route; the Logs dashboard "Latest error records" has the exception with
its trace. A single broken route during a sale: consider disabling the
feature for the brand rather than rolling back.

### KonventixCheckoutSlow

Checkout p95 above three seconds for ten minutes. Compare with the
database (Pulse slow queries under `admin/pulse`), the payment provider's
API latency (webhook p95 on the Sale dashboard is a proxy), and CPU per
role. Scaling web replicas helps only if CPU is the limit.

### KonventixErrorsLogged

The application logged error or worse in the last five minutes. Open the
Logs dashboard "Latest error records". Known noise gets fixed at its
source, not filtered here; this alert is the generic "something is
wrong" and stays sensitive on purpose.

### KonventixScheduleStale

`health:schedule-check-heartbeat` has not run for five minutes: the
scheduler pod is down or wedged. `kubectl -n konventix get pods -l
app.kubernetes.io/component=scheduler`; its log shows `schedule.task`
records when healthy. Without it: no waiting-room ticks, no cart expiry,
no health checks, no backups.

### KonventixResourceHoldSweepStale

`resources:expire-holds` has not finished a run for ten minutes. The
scheduler runs it every minute; it releases *clocked* holds (direct holds
with a `hold_expires_at`) with reason `expired`. Seat bookings a
registrant picked are confirmed in the same transaction as their hold and
are never touched by it (ADR-0060), so nothing a participant sees is lost
while this fires — but an expired direct hold keeps its resource until
someone tries to confirm it, and the place looks taken. If
KonventixScheduleStale fires too, the scheduler is the cause. Otherwise
the command itself is failing: its `schedule.task` records on the Logs
dashboard carry the exception, and
`kubectl -n konventix exec deploy/konventix-scheduler -- php artisan
resources:expire-holds` runs one sweep by hand. The series only exists
once the command has completed at least once on the instance, so an
instance whose brands never switched Resources on never trips this alert;
the sale dashboard's "Seconds since hold sweep" stat shows the same age.

### KonventixDatabaseBackupMissing

No CNPG backup completed in 26 hours. `kubectl -n konventix get backups`
and the CNPG cluster's status show the last attempt and its error; the
object store credentials are the usual cause. WAL archiving may still be
fine (next alert) but point-in-time recovery needs a base backup.

### KonventixWalArchivingFailing

The last WAL archive attempt failed after the last success, for fifteen
minutes. Recovery point objective is degrading by the minute. Check the
CNPG cluster status and the object store; disk on the primary fills once
WAL cannot leave.

### KonventixPodsRestarting

A pod restarted more than three times in thirty minutes. `kubectl -n
konventix describe pod <pod>` shows OOMKilled or a failing probe;
`kubectl logs --previous` shows the last words. Memory limits per role
are in the chart values.

### KonventixClaimInviteMailFailing

More claim-link invitations failed to send than were sent in the last
half hour (#292). The links exist — the buyer was told to copy them — so
nobody is locked out, but a manager whose invitations never arrive stops
trusting the feature. `SendMessage` refused: look for "Claim link mail
could not be sent." in the logs, which carries Messaging's reason — no
resolvable address (the link is bound, so this means a malformed one got
past validation), a brand with no mail identity, or the mail transport
itself, in which case `KonventixQueueJobsFailing` fires beside this.

### KonventixMailQuotaExhausted

A brand's mail sending quota for the hour or the day has been fully used
for five minutes (see "Mail quota" above): the count Messaging keeps of
mail the transport accepted has reached the limit, so every further
non-urgent mail is being held with `hold_reason = mail_quota` and will be
released when the window reopens — the next UTC hour, or the budget's
next midnight (UTC for the shared budget, the brand's own timezone for a
brand with its own transport; see "Mail quota" above). Nothing needs
rescuing: the delivery log
(`/admin/messages`, status *held*) lists what waits and until when, and
the sale dashboard's "Mail held for quota per hour" panel shows the
backlog forming. What to decide is whether the limit is right. For a
brand on the default mailer the budget is the provider's and shared with
every other brand on it; if it fires during a sale, raise
`MAIL_QUOTA_PER_HOUR` / `MAIL_QUOTA_PER_DAY` only once the provider has
raised its own limit, or move the brand onto its own SMTP transport with
its own budget (console, mail settings). For a brand with its own
transport, the override on its mail-settings row is the knob. An hourly
alert that resolves itself within the hour on a publishing day is the
system working; a daily one on a sale day means the confirmations of the
last hours are queued until midnight and is worth a decision before then.

### KonventixConsentGateBlocking

More than five consent prompts were raised for a brand in fifteen minutes
and not one agreement was recorded (ADR-0066, D-CPL-17). Everybody is
being asked and nobody is agreeing, which almost never means everybody
changed their mind. The sale has stopped and nothing else notices: no 5xx,
no failed job, no queue backlog — the logs look healthy, which is exactly
why this rule exists.

**The checkout has had two steps since ADR-0066 (D-CPL-07)**, which is
worth knowing before reading any of this. `/pay/registration/{id}`,
`/pay/order/{id}` and `/cart` are step one: the order, the deadline and the
settlement accounts, and **no legal text at all**. The texts and the one
accept control live on `…/confirm`, resolved for the provider chosen in step
one, and a brand with a single offered account is redirected past step one
on both pay pages. So a report of "the checkout shows no terms" is the
expected state of step one, and the page to look at is the confirmation.
Going back to step one clears the recorded provider and the acceptance with
it, by design — a payer may not be held to a list they never saw.

Three causes, in the order they are worth checking. **An unpublished or
un-effective text**: the interstitial and the checkout slot render nothing
to agree to when the policy a gate asks for has no published version whose
`effective_at` has arrived, so the control is empty and the POST has
nothing to record. `/admin/policies` for the brand, and the settlement
console's gap notice (#222), say which slug is missing. **A redirect
loop**: a gate that sends somebody to `policies.required` where the same
gate applies again — `require-contract-consent` is applied by each owning
module to its own routes and must never reach `policies.*`, the login
routes, `/my/*` or Support's report channel (T-9, ADR-0063). Watch one
request in the access log (`http.request` records) and see whether the
redirect repeats. **A broken control**: check the browser console on the
page the dashboard's "Prompts per hour by surface" names as the surface —
a page that never renders the checkbox cannot be agreed to.

The panel beside it is "Grants against prompts per hour". The refusal rate
is derived from those two series and is not a counter: the interstitial's
"Not now" increments nothing, on purpose, so a high gap is this alert's
evidence and not a separate number to chase.

### KonventixEntryConditionRefusalsAtDoor

More than three entry-condition declarations were refused at the entrance
in ten minutes for one brand (ADR-0066, D-ADM-06). Two readings, and the
door staff can usually tell them apart in a minute: a reduced-fare type is
being abused — people bought a student ticket without a student ID — or
the door is misreading the hint and refusing people who did bring what was
asked for.

Look at the registrations index filtered on entry-condition state for that
brand: it lists which condition is being refused and what the ticket told
the holder to bring (`document_hint`, printed on the ticket PDF). If the
hint and what the door is asking for disagree, fix the hint on
`/admin/entry-conditions` — it reaches tickets rendered from that point on
and the admissions issued afterwards; admissions already issued keep the
list they were stamped with, so tell the door directly as well.

Nothing needs rescuing at the turnstile either way. A refusal records a
fact: it never revokes the admission and never reaches Commerce (T-8), so
nobody is turned away by the system and no money moves. Whether a refused
person is let in is the operator's decision at the door, and an operator
who wants a *blocking* check sets `approval: required` on the registration
type instead, which is decided before the event rather than at the
entrance.

### KonventixSupportPurchaseAffected

Somebody has just reported a problem in the **purchase affected** category
(D-SUP-06). This is not a rate and not a threshold: it fires on the first
one in ten minutes, because the category means a person is standing in
front of a checkout that is not working for them right now, and nothing
else on this dashboard can tell you that. A quiet day with one of these is
the system working as intended.

`{{ $labels.brand }}` names the brand host the report came in on, or
`console` for a report filed on the operator console.

What to do, in order. **Open the console inbox**
(`/admin/support-requests`, filtered on the category) and read the newest
report: the reference is its last eight characters, upper-cased, and it is
the word the reporter has as well. **Paste the page correlation id into the
Logs dashboard** — the report carries the id of the request that
misbehaved, not only of its own submission, so the log lines behind the
problem are one query away and need no guessing about timestamps. **Check
whether it is already known**: the platform dashboard's Support row, the
payment panels of the sale dashboard and `KonventixPaymentFailureRate` say
whether this is one person's browser or everybody's checkout. If it is
everybody's, mark the report as a known issue with a notice — the dialog
then shows that notice to the next person about to report the same thing,
which is both kinder and quieter.

If several of these arrive within minutes of each other, treat it as a
payment incident and not as support volume: the reports are the symptom,
and `KonventixWebhookSilence` / `KonventixPaymentFailureRate` are where the
cause usually shows.

### KonventixSupportUnseen

A support report has been sitting in `received` — nobody has opened it in
the console — for more than an hour. The gauge is the age of the *oldest*
unopened report and reads 0 when there is none, so the alert resolves the
moment somebody opens the oldest one.

This is a staffing signal, not a fault: nothing is broken, somebody is
simply not reading the inbox. On a sale day or during the event that is
worth fixing within minutes, because the reports arriving then are the ones
that say the door or the checkout is broken. Off-season an hour is
harmless and the alert can be acknowledged.

Opening a report sets `seen` automatically, which is what ends the clock —
there is no separate "acknowledge" control to forget. If the inbox looks
empty while this is firing, check the brand filter: the gauge counts every
brand *and* the console-filed reports, and the console ones appear under no
brand at all.

### KonventixShoutboxBroadcastFailing

More than a fifth of a brand's shoutbox broadcasts failed over ten
minutes, with at least five attempted (see **Shoutbox** above). Participants
can still post — every line is saved — but nobody sees anybody else's line
until they reload, so the wall has stopped being live. The cause is almost
always Reverb: check the reverb role is up (`KonventixRoleUnavailable` fires
beside this if it is not) and that the brand's application credentials are
in place (**The brand's Reverb credentials**). The reported exception in the
Logs dashboard names the failure; a `QueryException` instead of a
`BroadcastException` means the broadcast could not read the line it was
sending, which points at the database rather than Reverb. Nothing needs
replaying once Reverb is back: clients catch up over HTTP when their socket
reconnects.

### KonventixRoleUnavailable

A deployment has had unavailable replicas for ten minutes. `kubectl -n
konventix get deploy` and `describe` on the one named; an image pull
error, a crash loop, or a node without room. Web unavailable means
visitors see errors; pulse unavailable means only the dashboard is stale.
