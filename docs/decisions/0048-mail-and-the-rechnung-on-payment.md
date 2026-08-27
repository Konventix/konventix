# ADR-0048: Every paid order gets a Rechnung, and the module that owns an event owns its mail

**Status:** accepted

## Context

Until now only an invoice-settled registration order produced a document: `IssueInvoice` at placement, a ZUGFeRD PDF, an archive entry, one mail. A card-paid registration and every shop order (ADR-0047) produced nothing — no receipt, no confirmation — and the registration lifecycle sent no mail at all. Identity's password-reset and verification mails were Laravel's stock notifications: unbranded, outside Messaging's per-brand sender (ADR-0028) and delivery log (ADR-0041).

Two questions were open: what document a paid order should get, and where the code that sends a mail should live.

## Decision

1. **A paid order gets a Rechnung, at the moment it is paid.** On `OrderPaid` for an instant or cart order, Commerce issues an invoice in the same gapless per-brand series (ADR-0031), with `due_on = issued_on` and a payment snapshot (`paid_at`, `paid_via`, `payment_reference`). The PDF states that it is paid; the Factur-X XML carries the payment means and the prepaid amount. It is archived and mailed exactly like an open invoice. There is no separate receipt document type: one series, one renderer, one retention rule, and credit notes (ADR-0036/0037) apply unchanged. Exactly one Rechnung per order; an invoice-settled order paid later gets no second document.
2. **A credit note gets a document too.** `credit_notes` gain the same `document_*` columns; a Gutschrift PDF is generated and mailed when issued.
3. **The module that owns the event owns the mail.** Commerce, Registration and Identity each listen to their own events and call Messaging's `SendMessage`; Messaging never listens to another module's domain events for this (its two intent-event exceptions in ADR-0041 stay the only ones). The catalogue: `commerce.order-confirmation`, `commerce.invoice-issued` (paid/open wording), `commerce.payment-failed`, `commerce.credit-note-issued`, `commerce.operations` (topic), `registration.submitted|approved|rejected|confirmed|cancelled`, `identity.verify-email`, `identity.password-reset`, `identity.welcome` (topic). Contractual and legal mails carry no topic and cannot be muted; only the suppression list applies.
4. **Messaging owns the look.** One branded layout (brand logo and accent colour, operator legal line, imprint and privacy links, a preferences link only where a topic exists) wraps every template; brand identity for mail is a read model Messaging resolves per send, never a singleton holding a brand. `SendMessageRequest` carries attachments as a first-class list.
5. **Identity's auth mail goes through Messaging.** `User` overrides the two notification hooks; Laravel keeps generating the signed links and throttling, Messaging delivers.
6. **Operators can see and resend.** The delivery log and the per-brand template overrides get their first screens.

## Alternatives rejected

- A separate "Quittung/Zahlungsbeleg" type with its own series: a second numbering discipline under GoBD and no legal benefit for a B2C sale — a paid Rechnung is the conventional German e-commerce document.
- Rechnung only on request: B2B payers must ask, the shop grows two paths, and a paid order would have no document in the archive.
- Messaging listening to `OrderPaid`/`RegistrationConfirmed`: Messaging would have to know what those mean; ADR-0041 already rejects that shape.
- Laravel Notifications with a custom Messaging channel: they route around per-brand transports, template overrides and the delivery log.

## Consequences

- Every paid order appears in the invoice series and the archive from the day this lands; the bookkeeping export (ADR-0046) sees shop revenue as invoices with `paid_via`.
- A refund of a shop order works end to end (invoice → credit note → PDF → mail).
- The ROADMAP's "receipt for instant payments" gap closes; a manual address completion for B2C invoices above 250 € is a follow-up (§14 UStG vs §33 UStDV).
- Mail volume rises (one to three mails per registration or order); Messaging's queue, suppression list and digest windows already exist for it.
