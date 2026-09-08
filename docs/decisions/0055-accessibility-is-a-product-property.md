# ADR-0055: Accessibility is a product property, not an operator's obligation

**Status:** accepted

## Context

The BFSG (Barrierefreiheitsstärkungsgesetz, Germany's transposition of the
European Accessibility Act, in force since 28 June 2025) covers consumer
services in e-commerce, which a ticket shop is. It exempts micro-enterprises
that provide services — § 3 Abs. 3 with § 2 Nr. 17: fewer than ten employees
and at most two million euros of turnover or balance-sheet total — regardless
of legal form. The first operator, Sund-Xplosion e.V., is far below both
thresholds (GitLab #65 records the check), so it owes neither WCAG conformance
nor the accessibility information of § 14 and Anlage 3.

That exemption belongs to the operator, not to the software. Konventix is
open core (ADR-0011) and meant for other operators (ROADMAP: multi-operator
groundwork); an organiser with ten employees, or a GmbH, is not exempt and
needs WCAG 2.1 AA (EN 301 549 as the reference of the BFSGV) plus the Anlage 3
information. The project has behaved that way from the start — ADR-0008
refuses a brand theme whose token pairs fail 4.5:1, eslint refuses palette
colours, slice 16 ran a keyboard pass and wrote `.ai/rules/js.md` — without
ever saying why. And every rule so far came out of the console, which is the
one surface the law does not cover: the public purchase flow had no rule at
all, no form error was linked to its field, no navigation moved focus, and
the cart's expiry display froze at render time.

## Decision

1. **Consumer-facing surfaces target WCAG 2.1 AA regardless of who operates
   the instance.** That is the brand landing and event pages, registration,
   shop, cart, payment, waiting room, the account area, policy pages and
   transactional mail. The console keeps the slice-16 standard — keyboard
   reachability and contrast — and no assistive-technology run is owed there.

2. **Each operator checks its own exemption and owns its own statement.** The
   figures that decide § 2 Nr. 17 are operator data and stay in the operator's
   records (for the first operator: GitLab #65), never in this repository.
   Whether an operator publishes accessibility information (§ 14 BFSG, Anlage
   3) is the operator's duty; Konventix neither ships nor generates one. A
   content-page template for it can come when a non-exempt operator arrives.

3. **The accessible form of a PDF is the page and the mail that carry the same
   facts.** Invoices, credit notes and badges come from dompdf (ADR-0031),
   which produces untagged PDFs. PDF/UA is not pursued; the account pages and
   the transactional mails must carry everything the PDF says. A PDF that says
   something no page says is the bug.

4. **Time limits are visible, announced and explained — never extended.** A
   cart hold expires when it expires: a seat held longer than necessary is the
   rush unfairness ADR-0047 exists to prevent, which is the essential
   exception of WCAG 2.2.1. In return the remaining time counts down visibly,
   a status message warns two minutes before expiry, the page explains what
   happened afterwards, and the hold duration is stated before anything is
   reserved (GitLab #182).

5. **Hosted checkouts are third-party content.** Stripe's and Mollie's pages
   are theirs (ADR-0039); Konventix owes an accessible way to them and back.

6. **Rules live next to the code, and a check runs in the pipeline.**
   `.ai/rules/js.md` carries the public-flow rules — form errors linked to
   their field, focus after navigation, live regions, language of content,
   time limits — and `eslint-plugin-vuejs-accessibility` plus an SSR-HTML test
   over every public route guard them (GitLab #183). The mistakes slice 16
   found were all in one shared component; the rule is what keeps them from
   coming back.

## Consequences

- Follow-ups from the 2026-09-07 review: form-error wiring (#180), navigation
  focus and live regions (#181), the cart countdown (#182), the automated
  check (#183). The assistive-technology run over the public pages (#19)
  follows #180 and #181, or it only rediscovers what is already known.
- The page's `lang` must follow the brand's locale, and content in another
  language is marked as such (#132). Until then German content sits in
  `lang="en"` pages, which screen readers read with the wrong voice.
- A module that ships a public page without the js.md rules has not finished
  the page.
- Nothing here changes the first operator's legal position; it is exempt. The
  standard is a product property, and the reason it exists is the second
  operator.
