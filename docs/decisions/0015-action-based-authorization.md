# ADR-0015: Authorization lives on Actions and Queries, not on controllers

**Status:** accepted

## Context

ADR-0013 settled *where capabilities and roles live* — capability enums in code, roles as
brand-scoped data. It did not settle *how authorization is enforced*. LanCore answers that
question with a controller-level convention, and the measurements say the convention does
not hold:

| Fact | Count |
|---|---|
| `$this->authorize(...)` calls in controllers | 268 |
| Controllers total | 104 |
| **Controllers with no `authorize()` call at all** | **40 (38%)** |
| `hasPermission()` calls | 227 |
| Policies | 42 |
| Actions | 175 |
| **Actions that check authorization** | **0** |
| Jobs / listeners / console files invoking Actions | 27 |

Two failure modes, both structural rather than sloppy:

1. **The miss rate.** 38% of controllers carry no authorization call. Nothing can tell you
   which of those are legitimately public, because the check is optional by construction.
2. **The bypass.** Behaviour lives in `Action::execute()`, which runs whatever it is handed.
   Every non-HTTP entry point — 27 files of jobs, listeners and commands today, plus the
   whole Extensibility API later — reaches the behaviour without passing any gate.
   Authorization is a property of one route into the operation, not of the operation.

The rules themselves were fine. LanCore's `OrderPolicy::view()` is a good rule. The problem
is that nothing guarantees a rule is consulted.

## Decision

**Authorization attaches to the operation, and every operation is a class.**

Two families, because writes and reads need structurally different authorization: a write
needs a **gate** (yes or no, deny loudly), a read needs a **filter** (narrow the set, return
what is visible). One mechanism bent to cover both fits neither.

### Writes — authorizing Actions

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

    public function execute(Registration $registration): void
    {
        DB::transaction(fn () => /* ... */);
    }
}
```

The dispatcher resolves the actor, checks the capability, runs the row-level `authorize()`,
and only then calls `execute()`. There is no route to the behaviour that skips the gate.
Controllers shrink to `$this->dispatch(ConfirmRegistration::class, $registration)`.

`DB::transaction` stays **inside** the Action, as `CLAUDE_extra.md` already requires. The
dispatcher does not own transactions: an Action that needs several boundaries, or none (an
external API call that must not sit inside a transaction), stays free to say so, and the
reader sees the boundary in the file that owns the behaviour.

### Reads — Query objects

```php
final class VisibleRegistrations implements Query
{
    public static function capability(): PermissionEnum
    {
        return Permission::ViewRegistrations;
    }

    public function for(Actor $actor): Builder
    {
        return Registration::query()->unless(
            $actor->can(Permission::ViewAllRegistrations),
            fn ($q) => $q->where('user_id', $actor->id()),
        );
    }
}
```

The capability is checked once; the scope *is* the row-level authorization. Collections work
because filtering is the native shape of a read — no per-row `authorize()` over 500 rows, one
database round trip. Queries never open a transaction.

**The rule: if it changes state it is an Action; if it only reads it is a Query.**

### Actors

`Actor` wraps the authenticated user or `SystemActor`. Non-HTTP callers pass one explicitly:

```php
$dispatcher->as(Actor::system('queue:retry-confirmation'))
    ->dispatch(ConfirmRegistration::class, $registration);
```

A nullable actor is not permitted. Privileged execution must be greppable and must appear in
the audit trail as *the system did this*, never as *nobody checked*.

### UI affordances

Pages declare the handful of checks they need, resolved per page:

```php
return Inertia::render('registrations/Show', [
    'registration' => RegistrationData::from($registration),
    'can' => [
        'confirm' => $dispatcher->can(ConfirmRegistration::class, $registration),
        'cancel'  => $dispatcher->can(CancelRegistration::class, $registration),
    ],
]);
```

`can()` consults the same object that enforces, so the button and the gate cannot drift.
There is deliberately **no** global capability map in shared props: LanCore computed every
permission on every request and it became a documented performance problem
(lessons-from-lancore #5).

### Cross-brand responses

A resource belonging to another brand is a **404** by default — brands are separate public
identities and existence must not leak across them. It is a **403** only when the actor holds
an operator-level capability that legitimately spans brands, which is the same escape hatch
ADR-0010 already requires for `withoutBrandScope()`.

### Denials

Denied **Actions** are written to Compliance's audit trail; denied Queries are not. A refused
state change is the security-relevant event and its volume is bounded. Read denials are
high-volume and mostly benign, and a misconfigured page could flood the trail that exists for
data-protection purposes.

## Consequences

- Authorization cannot be bypassed on any write path, from any entry point. Extensibility's
  external API inherits enforcement rather than re-implementing it.
- The capability an operation requires is declared in the file that performs it, so "what may
  this do" is answerable by reading one class.
- Two class families mean a boundary question on every new feature. It is almost always
  obvious; where it is not (an expensive export, a "mark as seen"), the state-change test
  decides, and the answer goes in the module's SPEC rather than being re-argued.
- Laravel's policy ecosystem is deliberately sidelined. `@can`, `authorizeResource` and
  Inertia's policy helpers no longer tell the whole story, so they must not be half-used
  alongside this. New developers have one thing to learn that their Laravel experience does
  not already cover.
- `SystemActor` appears throughout job and listener code. That verbosity is the point.
- Queries owning their scoping means "you have no access" and "there is nothing here" are
  indistinguishable to the caller. Accepted deliberately: it leaks less, and a caller that
  needs the distinction is describing a write.
- An architecture test must assert that every Action implements the contract and that
  controllers do not query models directly, or the two families quietly become three.
