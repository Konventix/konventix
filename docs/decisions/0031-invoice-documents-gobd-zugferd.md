# ADR-0031: Invoice documents are immutable, GoBD-archived, hybrid ZUGFeRD

**Status:** accepted

## Context

Commerce's SPEC lists invoice document integrity as open: "PDF rendering,
immutability, archival, and GoBD-compliant retention — including whether
e-invoicing formats (ZUGFeRD / XRechnung) are release scope or later." ADR-0006
already commits Commerce to owning its own `Order` and `Invoice` entities and
to per-brand invoice number ranges "legally clean when brands appear as
separate identities" — but says nothing about the document itself: what an
invoice *is* once issued, and in what format it leaves the system.

Germany is mid phase-in of mandatory B2B e-invoicing (structured formats
becoming the norm for domestic B2B transactions, with ZUGFeRD's hybrid
PDF+XML shape and the public-sector XRechnung profile as the two established
options). This is regulatory background, not a deadline this ADR is racing —
but it means "just render a PDF" is not a durable answer for exhibitor and
B2B invoices even if it were acceptable for the release itself.

## Decision

**Invoices are immutable once issued, archived under GoBD-compliant
retention, and rendered as hybrid ZUGFeRD — PDF/A with embedded structured
XML — for every invoice Commerce issues. XRechnung (the public-sector
e-invoicing profile) is deferred.**

- Once an `Invoice` is issued, its content never changes. A correction is a
  credit note or a new invoice against the same order, never an edit —
  matching ADR-0006's "partial credit notes live in the domain model" and
  keeping the audit trail the tax office expects: nothing issued is ever
  silently rewritten.
- Every invoice — B2C visitor ticket or B2B exhibitor invoice alike — is
  rendered as PDF/A-3 with an embedded ZUGFeRD XML payload (Factur-X profile).
  Hybrid ZUGFeRD is a strict superset of a plain PDF: any PDF viewer still
  renders it correctly, so there is no format branching by recipient type
  and no second invoice pipeline for the common visitor-ticket case.
- GoBD-compliant archival means the issued document is stored unaltered,
  retrievable in original form, for the statutory retention period, separate
  from whatever downstream copy a customer receives by email.
- XRechnung — the pure-XML, public-sector-oriented profile — is not built.
  Konventix's customers are private organizers and their exhibitors/sponsors,
  not public administrations invoiced through a Leitweg-ID; XRechnung has no
  addressee at release.

### Alternatives considered

**Plain PDF for all invoices, ZUGFeRD only where a B2B mandate applies.**
Smaller rendering surface for the common case, and defers the ZUGFeRD XML
work until a B2B exhibitor invoice actually requires it. Rejected: it
requires knowing at render time whether the *recipient* is covered by the
phase-in, which is exactly the kind of per-invoice branching that erodes
under schedule pressure and produces two invoice pipelines to keep in sync.
Since hybrid ZUGFeRD costs nothing extra to a human reading the PDF, there is
no offsetting benefit to the branch.

**Ship plain PDF only, defer both e-invoicing formats.** Cheapest to build,
and correct for the B2C-only case. Rejected because it is not correct for the
exhibitor/B2B invoices the platform already supports (ADR-0005's exhibitor
example), and because retrofitting structured XML into an "immutable once
issued" invoice model later means every invoice issued before the retrofit
is missing it forever — better to build the one pipeline once.

## Consequences

- Rendering depends on a PDF/A + ZUGFeRD XML generation library; the XML must
  be built from the same order-line snapshot ADR-0017 already produces
  (amounts, tax treatment, rounding) — it cannot be a second, independently
  maintained representation of the invoice.
- "Immutable once issued" means the refund-policy open question (Commerce
  SPEC's open question 5, still unresolved) now has a sharper shape: a refund
  can never edit the original invoice, only add a credit note beside it. This
  ADR does not answer what a refund means for an already-checked-in
  admission — it only forecloses "just change the invoice" as an option.
- GoBD retention (ten years under §147 AO) is a storage-lifecycle
  commitment, not a one-time rendering decision: archived invoices must
  survive brand deletion, operator changes, and any future storage
  migration, unaltered. This ADR names the requirement; the retention job
  itself is unbuilt.
- §19 UStG-exempt operators (ADR-0030) need a template variant that omits
  the VAT line and states the exemption in its place — the immutability and
  ZUGFeRD decisions apply to that variant identically, it is still one
  invoice, one archive path, one XML schema, just a different set of
  populated tax fields.
- XRechnung's absence is a real gap the moment any customer is a public body
  (a municipality-run event, a public university partnership) — this ADR
  accepts that gap rather than build a second output format against zero
  confirmed demand.
