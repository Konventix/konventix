# ADR-0018: Door payment is a Commerce point of sale

**Status:** accepted

## Context

LanCore's entrance API returns `payment_required` with an amount and line items
when a ticket's order is on-site and unpaid, and `POST
/api/entrance/confirm-payment` books the payment, dispatches a receipt and checks
the holder in — one call, from a device at the door. It works, and it is a direct
contradiction of ADR-0005, which states that check-in asks "is this admission
valid?", never "is it paid?". Admission's SPEC repeats the rule. The predecessor
shows how easy the rule is to break, and why: the door is the one place where
taking money and granting access genuinely feel like a single act.

Cash at the door is nonetheless a real and common workflow that our own events
run today. It does not violate ADR-0007: cash collected by an organizer's staffer
never touches the platform, so no ZAG question arises. The question is not
whether to support it but where it lives.

## Decision

**The door gets a Commerce point of sale that happens to be at the door.**

- The entrance client gains a separate, separately authorized POS screen that
  calls Commerce endpoints to book a payment. It is not part of the validate
  endpoint and is not reachable with scan-only capabilities.
- Booking a payment emits `PaymentReceived` / `OrderPaid`, reusing the manual
  payment booking that ADR-0006 already makes first-class.
- Registration consumes the event and confirms if its `confirmation_gate` says so
  (ADR-0005). Admission issues the admission. The operator then scans it.
- Two operations in the correct causal order. There is no combined "collect and
  admit" endpoint, in either module.

### Alternatives considered

**Drop door payment entirely.** On-site settlement would be booked in Commerce's
back office before or after entry; an unpaid registration is simply not confirmed
and has no admission to scan. ADR-0005 stays pristine and the offline client
stays trivially safe. Rejected because it removes a workflow our own events use,
and the practical result is a second queue and a laptop at the door running the
admin UI, which is worse for the organizer than what LanCore already has.

**Keep a combined endpoint, but move it into Commerce.** Best operator
experience, closest to today's behaviour, and the orchestration would at least be
explicit and in one place. Rejected because it recreates the payment-to-access
coupling as an API convenience, and conveniences become assumptions — that is
precisely the road by which LanCore arrived at `1 ticket = 1 gamer`
(lessons-from-lancore #12). It would also have to wait for an asynchronous
confirmation, forcing either synchronous cross-module calls (forbidden by
ADR-0004) or a polling story at the busiest moment of the event.

## Consequences

- Two round trips with an event-driven hop between them, so the operator may
  briefly see "not yet valid" after having taken the cash. This needs deliberate
  UX handling rather than a spinner: collect payment, show "confirming…", the
  admission appears, then scan. If that latency is not designed for, door staff
  will invent a workaround and the boundary is lost anyway.
- Door payment requires connectivity and therefore cannot work in offline mode.
  That constrains D-COM-06: the entrance client's offline story now covers
  validation only, and losing the network means the door stops taking money, not
  merely that it scans from cache.
- The entrance client stops being purely an Admission client. It talks to two
  modules and needs two sets of capabilities.
- The capability split — collect money versus scan — becomes enforceable and
  matches Admission's "door staff get scan and print only". Under ADR-0015 the
  POS operation is an Action with its own capability, so a scanner cannot become
  a till by accident.
- The validate endpoint stays free of payment logic: no amount comparison, no
  receipt dispatch, no money model on the validation path.
