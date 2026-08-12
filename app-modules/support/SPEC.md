# Support — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

TODO: one paragraph — what this module owns, in domain language.

## Owns

TODO: the entities, state, and behaviour that belong to this module and to no
other. See `docs/architecture/module-map.md`.

## Does not own

TODO: the nearby concepts that belong to other modules, and which module has
them. This section is what stops boundary drift.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| TODO | | |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| TODO | | |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

## Activation

TODO: Kernel/Core modules are always active. Pennant-gated modules state their
feature name and what a brand loses when it is off.

## Open questions

TODO: unresolved design questions. Move them to ADRs once settled.
