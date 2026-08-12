# ADR-0005: Registration ≠ Admission; RegistrationType with explicit axes

**Status:** accepted

## Context

LanCore assumed `1 ticket = 1 gamer`: the commercial act and the access right
were one object. Exhibitors, speakers, press, crew, and B2B group bookings
break this — one purchase can produce many access rights, a booth booking, and
an invoice.

## Decision

- **Registration** — the commercial/contractual act. Has an invoice recipient
  (may be an organization), a status, possibly an invoice.
- **Admission** — one person's access right. Badge, role, check-in, resource
  claims. One Registration grants N Admissions/Bookings:

```
Registration (exhibitor "Musterfirma GmbH")
 ├─ ResourceBooking → booth B12, 12 m²
 ├─ Admission ×5    → role: exhibitor_crew
 ├─ Admission ×10   → role: visitor (voucher codes)
 └─ Invoice         → 1,450 € net, 14 days
```

- `RegistrationType` replaces the monolithic TicketType, with explicit axes
  instead of implicit assumptions:

| Axis | Values |
|---|---|
| `visibility` | public / link / invite_only |
| `approval` | none / required |
| `settlement` | free / instant / invoice / external |
| `confirmation_gate` | on_submit / on_approval / on_payment |
| `grants` | admissions, roles, resource claims |

Examples: visitor ticket = public/none/instant/on_payment; exhibitor =
public/required/invoice/on_approval; speaker = invite_only/none/free/on_submit;
crew = invite_only/none/free/on_approval.

- **`confirmation_gate` decouples confirmation from payment.** Registration
  status and payment status are two independent fields. Invoice-settled
  registrations are confirmed on approval; payment is monitored separately
  (due dates, dunning).
- **Quota** is a standalone entity, not a column on a type.

## Consequences

- Four fundamentally different processes run on one model.
- Commerce must not own registration status (see ADR-0006).
- Admission check-in logic never asks "is it paid?", it asks "is the admission
  valid?".
