# ADR-0040: Bookkeeping export — DATEV Buchungsstapel plus readable journal

**Status:** accepted

## Context

Commerce owns "bookkeeping exports for the tax advisor" (SPEC), but no
format was ever decided — the roadmap explicitly parks Slice 7c until one
is. D-COM-05 already settled the shape of the taxpayer: invoice numbers are
per brand, and the export must be able to emit **all brands of one operator
as a single file**, because the operator is the legal entity the tax
authority sees.

What a German tax advisor actually imports is the DATEV-Format: a
`Buchungsstapel` CSV (`EXTF` header, format version 700/13, 125 booking
columns). Anything else means manual re-keying on their side.

## Decision

Interview decisions (2026-08-16): **both formats** — the DATEV
Buchungsstapel for import plus a human-readable CSV journal as a checkable
companion; **SKR03** defaults; **documents only** (invoices and credit
notes — no payment bookings, the advisor books those from the bank/PSP
statements they already import, and duplicating them causes double
bookings); **one collective debtor account**.

1. **Scope and grain.** One export per operator and calendar month, all of
   the operator's brands in one file. One booking row per document and tax
   bucket: an invoice mixing 19 % and 7 % lines produces two rows sharing
   the same document number, so each revenue account receives exactly its
   gross share and the sum of rows equals the invoice. A credit note books
   the same buckets with the opposite posting side, pro-rated by the
   invoice's own line split when partial (the same split its tax reuses).
   A refund of an uninvoiced `instant` order stays out: it produced no
   document, and the money side arrives with the PSP statement.

2. **The DATEV file.** `EXTF` header (version 700, category 21, format 13),
   the canonical 125-column layout, CP1252, semicolons, CRLF —
   the advisor's import recognizes it as a native batch. Postings follow
   the standard debtor pattern: `Konto` = the collective debtor,
   `Gegenkonto` = the revenue account, `S` for invoices / `H` for credit
   notes, amount always positive, `Belegdatum` as `DDMM`, the document
   number in `Belegfeld 1`, brand + buyer in the posting text. The SKR03
   defaults are **Automatikkonten** — 8400 (19 %), 8300 (7 %), 8336
   (EU reverse charge), 8195 (§19 UStG) — so the `BU-Schlüssel` stays
   empty and DATEV derives the tax itself; reverse-charge rows also carry
   the buyer's VAT ID in `EU-Land u. USt-IdNr.`. The batch is marked
   festgeschrieben (GoBD): it describes immutable, archived documents.

3. **The journal.** A plain CSV (UTF-8, semicolon) with one line per
   document — number, kind, date, brand, buyer, country, VAT ID, tax
   treatment, net/tax/gross — for the operator to eyeball and for advisors
   who do not run DATEV.

4. **Settings live in Commerce, keyed by operator ULID.**
   `BookkeepingSettings` carries the consultant/client numbers the header
   wants, the G/L account length, the collective debtor and the four
   revenue accounts, defaulting to the SKR03 set. Commerce does not touch
   the Operator model (the doorway rule stands); the operator id comes from
   the admin host's context, brands via the allow-listed `Brand` model.
   Fiscal year is assumed to start January 1 — a deviating fiscal year is
   out of release scope and would surface here first.

## Consequences

- The advisor receives one native DATEV batch per month covering every
  brand of the legal entity — D-COM-05's "single gapless view" promise,
  delivered.
- Because all default revenue accounts are Automatikkonten, no BU-key
  table exists to maintain; an operator whose advisor uses SKR04 or custom
  accounts edits four numbers, not a mapping matrix.
- Rounding never invents cents: bucket shares are computed in bcmath and
  the last bucket absorbs the remainder, so rows always sum to the
  document totals the archive shows.
- Payments deliberately never appear; if an advisor asks for
  Zahlungsbuchungen, that is a new decision against the double-booking
  argument, not a forgotten feature.
- The export reads only issued documents; re-running a month is idempotent
  and safe because nothing is marked "exported" — the advisor's import
  dedupes by batch, and operators are expected to hand over each month
  once.
