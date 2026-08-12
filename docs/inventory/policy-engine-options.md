# Policy engine — evidence and options

> **Superseded by ADR-0015.** This document records the analysis and the options as they
> were presented; the decision itself lives in `docs/decisions/0015-action-based-authorization.md`.

Input for the decision session. ADR-0013 settled *where capabilities and roles live*
(enums in code, roles as brand-scoped data). It did **not** settle *how authorization is
enforced*. That is this document.

## What LanCore does today

Measured against `/home/mkohn/git/lan-software/LanCore`:

| Fact | Count |
|---|---|
| `$this->authorize(...)` calls in controllers | 268 |
| Controllers total | 104 |
| **Controllers with no `authorize()` call at all** | **40 (38%)** |
| `Gate::` usages | 47 |
| `hasPermission()` calls | 227 |
| Policies | 42 |
| Actions | 175 |
| **Actions that check authorization** | **0** |
| Jobs / listeners / console files invoking Actions | 27 |

The shape: authorization is a **controller-level convention**, applied by hand 268 times,
and the behaviour it guards lives somewhere else entirely — in an `Action::execute()` that
will run whatever it is handed. Roles are a static `match()` in `RolePermissionMap` over
five hardcoded `RoleName` cases.

Two failure modes follow, and both are structural rather than sloppiness:

1. **The miss rate.** 38% of controllers carry no authorization call. Some are legitimately
   public; many are not. Nothing can tell you which, because the check is optional by
   construction.
2. **The bypass.** Every non-HTTP entry point — 27 files' worth of jobs, listeners and
   commands, plus every future API and console path — reaches `Action::execute()` without
   passing any gate. Authorization is not a property of the operation; it is a property of
   one route into the operation.

LanCore's own `OrderPolicy` shows the intended pattern working well:

```php
public function view(User $user, Order $order): bool
{
    return $user->hasPermission(Permission::ViewOrders) || $order->user_id === $user->id;
}
```

That is a good rule. The problem is not the rules — it is that nothing guarantees the rule
is consulted.

## What Konventix additionally requires

- **Brand awareness.** Every check happens inside a resolved brand (ADR-0010). A capability
  granted in brand A must not carry into brand B.
- **Module activation is separate.** Pennant gating (ADR-0003) answers "does this brand have
  this feature at all", authorization answers "may this actor do it". A route in an inactive
  module 404s before authorization is ever consulted; the two must not be conflated.
- **Capabilities stay enums** (ADR-0013). Whatever the engine is, it reads capability enums
  and brand-scoped role data — it does not invent a third source of truth.
- **UI affordances.** Inertia pages need to know which buttons to render. Whatever enforces
  must be the same thing the UI asks, or the two drift — LanCore's shared-props middleware
  computed all permissions on every request (lessons #5) precisely because they had diverged.

---

## Option A — Laravel policies, Actions stay pure

Keep the LanCore shape, fix it with rigour: controllers call `authorize()`, policies read
capability enums, Actions remain plain behaviour. Close the miss rate with an architecture
test asserting every non-public controller method authorizes.

**Pros.** Entirely idiomatic; `@can`, `Gate::allows`, `authorizeResource` and Inertia's
policy helpers all work unmodified. Zero new concepts — any Laravel developer is productive
immediately. Smallest possible diff from what already exists.

**Cons.** The bypass is not fixed and cannot be fixed by this option: an arch test can only
police controllers, and the 27 job/listener/command paths remain open. It re-commits to the
arrangement that produced a 38% miss rate, with discipline as the only remedy. As soon as an
external API exists (Extensibility), the whole surface must be re-guarded by hand a second
time.

## Option B — Authorizing Actions

The Action becomes the authorization boundary. Every state-changing Action declares the
capability it requires and, where needed, a row-level rule; a dispatcher enforces both
before `execute()` runs, from every entry point.

```php
final class ConfirmRegistration implements AuthorizingAction
{
    public static function capability(): PermissionEnum
    {
        return Permission::ConfirmRegistrations;
    }

    public function authorize(Actor $actor, Registration $registration): bool
    {
        return $registration->event->isOpenFor($actor);
    }

    public function execute(Registration $registration): void { /* ... */ }
}
```

Controllers shrink to `$this->dispatch(ConfirmRegistration::class, $registration)`. Jobs,
listeners and console commands use the same dispatcher, and privileged internal work passes
an explicit `SystemActor` — a single greppable token instead of silent absence. The
dispatcher also answers `can(ConfirmRegistration::class, $registration)` for Inertia props,
so UI and enforcement consult one object.

**Pros.** Authorization becomes impossible to bypass: there is no route to the behaviour
that does not pass the gate. One mechanism covers HTTP, queue, console and any future API —
Extensibility inherits it for free. The required capability is declared next to the
behaviour, so "what may this do" is answerable by reading one file. `SystemActor` turns
privileged execution into something auditable rather than something invisible.

**Cons.** A genuinely new concept, and the main one people must learn. Laravel's policy
ecosystem is partly sidelined — `@can` and `authorizeResource` no longer tell the whole
story, so the team must not half-use both. Reads are not covered: queries and page loads
still need their own answer. Ceremony is real for trivial actions, and a dispatcher in the
call path is one more thing to debug through.

## Option C — Hybrid: policies for reads, authorizing Actions for writes

Reads and UI visibility keep Laravel policies (`viewAny`, `view`). Every state change goes
through an authorizing Action as in Option B.

**Pros.** Plays to each mechanism's strength instead of forcing one to cover both. Keeps
idiomatic Blade/Inertia visibility checks, where policies are genuinely good, while making
the write path airtight, where they are genuinely weak. Migration is incremental: existing
read policies survive untouched.

**Cons.** Two mechanisms, so the first question on any new feature is "which one" — usually
obvious, occasionally not (an expensive export is a read that behaves like a write; a
"mark as seen" is a write that behaves like a read). Some rules end up expressed twice: a
policy's `view` and an action's row check can encode the same predicate and drift apart.

## Option D — Policy-as-data / ABAC engine

Store rules as data and evaluate them against actor, resource and context attributes at
runtime, authorable per brand without a deploy.

**Pros.** Maximum flexibility; a brand could express rules nobody anticipated. Rule changes
need no release.

**Cons.** Substantial engine to build, test and debug, and authorization bugs are security
bugs. It contradicts ADR-0013's decision to keep capabilities in code. Rules become
untestable in CI because they live in production data, and "why was this denied" turns into
an investigation. For a 1–3 person team this is the kind of thing that consumes a quarter
and is never finished. **Included for completeness; not recommended.**

---

## Sub-decisions that follow

These need answers whichever option wins, and several only make sense once it is chosen.

1. **Where do row-level rules live** — on the Action, or in a Policy the Action calls?
   Keeping them in policies preserves one home for "may this actor touch this record";
   putting them on the Action keeps rule and behaviour together.
2. **How are reads authorized** — policies, query scopes that filter rather than deny, or
   read-Actions? Filtering scopes leak less information than denials but make "you have no
   access" indistinguishable from "there is nothing here".
3. **How does the UI ask** — a `can` map in Inertia shared props (LanCore computed all
   permissions on every request and it was a documented performance problem), per-page
   explicit `can` props, or lazily via deferred props.
4. **The system escape hatch** — an explicit `SystemActor`, a `runAsSystem()` wrapper, or
   nullable actor. The first two are greppable; the third is what LanCore effectively has.
5. **Transaction ownership** — `CLAUDE_extra.md` says `DB::transaction` lives inside the
   Action. If a dispatcher wraps execution, does it own the transaction instead? Both cannot
   be true without nested transactions.
6. **Wrong-brand response** — 404 or 403? 404 leaks less across brand boundaries, which
   matters when brands are separate public identities; 403 is more honest to legitimate
   operators working across brands.
7. **Denial telemetry** — are denials audited? Compliance owns the audit trail, and a denial
   is often the most security-relevant event in the system.

## Recommendation

**Option C**, with row-level rules on the Action and policies confined to reads and
visibility.

Option A is disqualified by evidence rather than taste: it is exactly what produced the 38%
miss rate and the 27 bypass paths, and the one lever it offers — discipline — is the lever
that already failed. Option D is disproportionate. Between B and C, the deciding factor is
that Inertia pages need cheap, idiomatic visibility checks, and policies do that well; there
is no benefit in routing "should this button render" through a dispatcher. C keeps that,
and still closes the bypass on every path that changes state.

The honest cost of C is the two-mechanism boundary, and it should be written down as a rule
rather than left to judgement: **if it writes, it is an Action; if it only reads, it is a
policy.**
