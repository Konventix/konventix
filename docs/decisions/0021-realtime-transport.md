# ADR-0021: Reverb per brand domain, as a deployment role of the one image

**Status:** partially superseded by ADR-0025 — the per-brand *process* topology
is replaced by one shared Reverb server with one app entry per brand. Still
standing: Reverb as a deployment role, broadcasting-abstraction-only code,
LanShout's deletion, and the phase plan (bell + board in Phase 2, chat +
presence in Phase 3).

## Context

LanCore broadcasts five things over Reverb, with Echo and `pusher-js` on the
client: chat `MessagePosted`, `UserPresenceChanged`, the notification bell
(`NotificationReceived`), and two competition-board events. The transport works
and the `ShouldBroadcastNow` semantics are already correct.

The scheduling consequence is the part that forces the decision now. Chat and
presence belong to Community, which is Phase 3, so it would be natural to defer
realtime with it. But two of the five broadcasts belong to **release-scope**
modules: the notification bell is Messaging's, and the competition board is
Competition's. Realtime cannot wait for Phase 3 without those two shipping
degraded. Community's SPEC flags chat transport as needing an ADR; this is that
ADR, arriving a phase earlier than expected.

Konventix also makes the problem harder than LanCore had it. LanCore was one
instance on one domain. Konventix serves brands on arbitrary domains via
on-demand TLS (ADR-0001), so websocket origin, session and channel authorisation
all have to work per brand domain rather than once.

The related question is where the process runs. LanShout is the one satellite
whose value is not just code — it is a long-lived socket server, and Konventix
has never run one. ADR-0002 reserves runtime separation for genuine offline
requirements, of which this is not one, so the only open part is which
deployment role owns it.

## Decision

**Reverb runs per brand domain, as a deployment role of the one Docker image.**

- The image gains a fourth role alongside web, workers and scheduler. One image
  to build, one codebase, one set of channel authorisation rules — brand
  context, auth and policy all come from the same application rather than from an
  integration.
- Each brand's websocket endpoint lives on that brand's own domain. There is no
  cross-origin websocket handshake and no shared websocket host: the connection
  carries the brand's own session on the origin that issued it.
- Websocket isolation is therefore **structural rather than enforced in channel
  authorisation**. A brand's socket process serves that brand, so a mistake in
  channel naming cannot leak events across brands.
- LanShout is deleted rather than maintained. Its `FilterChain`, `ChatSetting`
  and timeout model drop into Community when Community ships.
- Application code is written against Laravel's broadcasting abstraction. The
  bell and the board broadcast in Phase 2; chat and presence inherit the
  transport in Phase 3.

### Alternatives considered

**One shared websocket host for all brands** — `ws.<operator domain>` —
authorising over the app session and using brand-prefixed channel names. Fewer
processes, one certificate, and scaling through Redis pub/sub is a well-trodden
path. Rejected on two grounds. Cross-origin websocket authentication from N
arbitrary brand domains is exactly the surface that on-demand TLS and per-brand
sessions make awkward, and it has to be solved once per browser quirk rather
than once. More importantly, brand isolation would then rest on a channel-naming
convention enforced by an architecture test — a convention that is correct until
someone writes a channel name by hand. ADR-0010 exists because that shape failed
in LanCore for event scoping; recreating it for websockets is a poor trade.

**Polling for the bell, SSE for chat.** No long-running process at all: the bell
polls a cheap endpoint and chat uses server-sent events over the existing HTTP
stack. Rejected because the competition board's live Gantt degrades to a refresh
interval, and it is a live-ops tool used while matches are running. Presence
also loses its point — a sweep that broadcasts nothing is a cron job — and SSE
under FrankenPHP worker mode would need proving on its own.

**No realtime at release.** The bell updates on navigation, the board refreshes
on an interval. Rejected: the board is a stated differentiator, and shipping it
without live updates undersells the thing Competition just absorbed LanBrackets
to own (ADR-0019).

**Keep LanShout external as the realtime application.** Out on ADR-0002, and the
evidence is damning independently: no Dockerfile in its tree, three silent
contract mismatches with LanCore, duplicated RBAC that has already diverged, and
a webhook verifier that fails open on an empty secret.

## Consequences

- The Reverb role is instantiated **per brand**, so deployment topology grows
  with brand count: a process, a supervision entry and a connection budget for
  every brand. This is ADR-0002's cost curve reappearing in the infrastructure
  layer rather than the application layer, which is where ADR-0002 pushed it out
  of. It is worth watching rather than worth reversing now, because brands are
  few and are created deliberately. **The trigger for revisiting is brands
  becoming cheap enough to create casually** — self-service brand creation, or a
  brand per event. At that point the shared-host alternative returns with its
  channel-naming problem entirely intact, and it will have to be solved rather
  than sidestepped.
- Konventix acquires a stateful process in an otherwise stateless stack, with its
  own connection limits, scaling behaviour and sticky-routing concerns. That
  operational learning now happens in Phase 2, on a team that has not run a
  socket server before.
- The infrastructure lands before its heaviest consumer. The bell and the board
  are low-volume broadcasts; chat and presence, which will actually load the
  process, are Phase 3. Per-brand sizing therefore gets decided on evidence that
  does not represent the eventual load, and will need revisiting when Community
  ships.
- Brand resolution has to work on the websocket handshake path, not only on HTTP
  requests. The socket shares the brand's certificate, which removes a
  certificate story a separate host would have needed, but it adds the brand
  domain to a code path that currently only middleware covers.
- Presence channels give Community's chat member list for free when it lands —
  one of the few Phase 3 pieces that arrives already solved.
