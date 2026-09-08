# ADR-0058: One Stripe account per instance — Konventix is not a marketplace

**Status:** accepted (2026-09-08, decision by the operator on 2026-09-08 for
#214; it supersedes and closes #161, renders #198 and #199 obsolete, and
rewrites the Stripe half of ADR-0007 and ADR-0039 point 5 — #215 is the
implementation)

## Context

Stripe is the only provider Konventix reaches through Stripe Connect. The
console creates a **new** connected account under the platform account
(`SdkStripeApi::createConnectedAccount()`, the v2 Accounts API with
`dashboard: full` and Stripe as fees and losses collector), sends the
organizer into Stripe-hosted onboarding (`createOnboardingLink()` →
`Inertia::location()`), marks the row connected on return, and from then on
acts *on* that account with the platform key plus the `Stripe-Account`
header — every Checkout Session, every refund, every capability read
(`SdkStripeApi`, `StripeSettlementAdapter`, `Console/ReconcilePayments`).
`SettlementAccount::isOffered()` additionally requires
`capabilities.card_payments === 'active'`, refreshed on return from
onboarding and daily by `RefreshSettlementAccountCapabilities`. The
webhook endpoint is a platform endpoint with "Listen to events on
connected accounts" enabled, verified against one platform signing secret
(`StripeWebhookController`, `docs/helm-chart-requirements.md`).

Mollie and Unzer, by contrast, connect in one step against the brand's own
key, stored encrypted on `SettlementAccount.credentials` (ADR-0039 point 4).
Stripe is the outlier.

Connect was chosen for a product that does not exist. The reasoning behind
it, read back:

- **ADR-0007** feared BaFin/ZAG licensing if the platform received
  participants' money on behalf of organizers, and answered "each organizer
  connects their **own** account". Connect Standard / v2 direct charges is
  one way to reach that outcome. It is not the only one, and it is the
  expensive one.
- **ADR-0039 point 5** pinned the SaaS/direct-charge pattern: the organizer
  is merchant of record, keeps the dashboard, pays the fees, carries the
  losses, and no `application_fee_amount` is ever set.
- **ADR-0046** allows one live account per provider per brand, so a brand
  can offer Stripe and Mollie side by side.

What the operator states, and what the code already agrees with:

- **Konventix is deliberately not a marketplace.** Several sellers means
  several Konventix instances. `Operator` is documented as "the legal
  entity running this instance (ADR-0001) — exactly one row today", and
  `BookkeepingSettings` is already *per operator, deliberately not
  brand-owned*, because "the operator is the taxpayer" (ADR-0040, D-COM-05).
  The invoicing identity, the VAT id, the §19 election and the DATEV
  account mapping are all operator-level already. Only the money route is
  modelled as if each brand were a different business.
- **Brands are the operator's own faces**, not other people's shops
  (ADR-0001). Asking one e.V. to run one Stripe account per brand — each
  with its own identity verification, bank account, dashboard, payout
  schedule and 1099/ELSTER-relevant reporting — is not a setup anyone
  operates.
- **Connect costs the platform a Connect platform account** it otherwise
  does not need, and the failure modes are the ones the 2026-09-07 session
  found: a 500 when `STRIPE_SECRET` is empty (#194), "Onboarding pending"
  with no explanation, an abandoned onboarding restartable only by creating
  a second Stripe account, and the "Leave/Stay" prompt on the hard redirect
  into onboarding (C10).
- **The cutover on 22.09** is from eventula-manager, which has run SXLAN's
  ticket sales for years on a plain Stripe account and does not use Connect
  at all. #146 (cutover readiness) already lists "Stripe Secret Key and
  Webhook-Signing-Secret" as the live credentials to procure — the key
  model, not the Connect model. #161 asked for the key mode *beside*
  Connect; keeping both means two code paths, two client constructions, two
  webhook-verification shapes and two of every test, forever. The operator
  has decided to close the question instead: one account, one key, no mode
  flag.

How eventula-manager does it, since it is the system being replaced
(repo `lan2play/eventula-manager`, default branch `main`):

- Keys live in an `api_keys` table (`stripe_public_key` / `stripe_secret_key`),
  encrypted through the model's own accessors (`src/app/ApiKey.php`),
  entered in `admin/settings/api.blade.php` and at install time; env wins
  when `ENV_OVERRIDE` is set, resolved into Omnipay's config at boot.
- The charge is a PaymentIntent created with the merchant's own secret key
  through Omnipay (`Omnipay::create('Stripe\PaymentIntents')`,
  `purchase(['confirm' => true, …])` in `PaymentsController::post()`),
  with card data collected on their own page by Stripe.js v3 Elements. **No
  `Stripe-Account` header, no connected accounts, no application fee and no
  PayPal adapter anywhere in the repository** — PayPal orders in that
  system's history were taken by hand, outside Stripe.
- There is **no webhook** at all: payment is confirmed synchronously in the
  browser return path. There is **no refund path**; the admin buttons are
  disabled placeholders. No metadata and no statement descriptor reach
  Stripe.

So eventula is the right model for **whose account** and **where the keys
live**, and the wrong model for **how settlement becomes true**. Konventix
keeps hosted checkout and webhook-confirmed settlement (ADR-0039 points 1-3)
and adopts only the account model.

## Decision

1. **Konventix settles into one Stripe account per instance — the
   operator's own.** Stripe Connect is removed. The instance holds one
   Stripe secret key and one webhook signing secret; every Checkout
   Session, refund and reconciliation call is made on that account with no
   `Stripe-Account` header, exactly the way Mollie and Unzer already act on
   the key they were given. ADR-0007's promise is kept *more* directly than
   before: the operator running the instance **is** the merchant of record,
   the money never touches a platform account, and nothing about ZAG
   changes. Several sellers means several instances (ADR-0001) — this ADR
   only writes down what "brand, not tenant" already implied for money.

2. **The credentials are operator-level, not brand-level, and not
   platform-level.** An encrypted Commerce-owned settings row, one per
   operator, holds the Stripe secret key and the webhook signing secret,
   entered through a console form — the same row shape
   `BookkeepingSettings` already uses: keyed by operator ULID,
   `forOperator()` returning a defaulted unsaved instance so nothing breaks
   before anyone opens the form. `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`
   remain fully supported from the environment (`secrets.payments`,
   unchanged), and the resolution order is stated plainly, in one place
   (`CommerceServiceProvider`'s `StripeApi` binding): **the operator
   settings row wins if it carries a key; otherwise the environment value
   is used.** Both stay supported permanently — the environment is what a
   fresh deployment sets before anyone has logged in, the row is what an
   operator can set or rotate without a redeploy. The webhook secret
   follows the same order: `StripeWebhookController` verifies against the
   resolved secret, not against `config()` directly.

   A publishable key is stored on the same row, optionally, purely for
   completeness — hosted Checkout never needs one, since no card field is
   ever rendered on a Konventix page (point 6). Keys are stored
   `encrypted:array` (`APP_KEY`), never logged, never returned to a page
   prop — the console shows *whether* each of the three is set, the secret
   and webhook secret's mode (`sk_live_` / `sk_test_`) and last four
   characters, and the publishable key only as "set" / "not set", the same
   "whether, never what" rule `StripeSetupData` already follows.

3. **A Stripe `SettlementAccount` row means "this brand sells through
   Stripe", nothing more.** `provider_account_id` and `capabilities` stop
   being written for Stripe; there is no onboarding, no return route, no
   capability sweep. `isOffered()` for Stripe becomes "live, and this
   instance has a Stripe secret key configured" — the same one-line answer
   Mollie and Unzer give. The row keeps `checkout_label` (ADR-0046) and
   gains an optional `statement_descriptor_suffix`, so what the payer reads
   on their card statement names the brand rather than the operator. Stripe
   caps the combined statement descriptor at 22 characters, account prefix
   included; the suffix is validated against the remaining budget at save
   time, not discovered as a rejected Checkout Session later. ADR-0046 is
   otherwise untouched — one live account per provider per brand, the payer
   picks, the `Payment` row names the account — except that the Stripe row
   no longer carries an account of its own to pick between.

   *Amended 2026-09-08, while #215 was being implemented (decision by the
   operator):* "a Stripe secret key configured" above is tightened to **the
   pair** — the secret key and the webhook signing secret. The original
   wording is left standing because it is what was decided; this is why it
   was not enough. ADR-0039 point 2 makes the signed webhook the only source
   of settlement truth, so a Checkout Session started against a key whose
   signing secret is missing takes the payer's money and leaves the order
   pending forever, with nothing on any page saying so. A button that is
   absent is a smaller failure than one that charges and never settles, so
   `isOffered()` for Stripe asks for both, `StripeSetupData` reports the two
   halves separately, and the console names the missing one. The `StripeApi`
   binding is deliberately *not* tightened the same way: refunds and the
   reconciliation sweep need only the key, and blocking them would turn a
   missing webhook secret into an outage rather than a closed door on new
   checkouts.

   The same amendment settles the key *shape*: a restricted key (`rk_test_`
   / `rk_live_`) is accepted beside `sk_`, because that is what `stripe
   login` mints and it charges the same account the same way. Which calls a
   restricted key may make is scoped at Stripe and deliberately not mirrored
   in this application — a call outside its scope fails with Stripe's own
   message, which explains the problem better than any guess made at save
   time. The mode and last-four display read `rk_` exactly like `sk_`, so
   ADR-0053's live-key warning fires for both.

4. **One account, many brands: the split is metadata, not accounts.**
   Every Checkout Session already carries `konventix_payment_id` and
   `konventix_order_id` in `metadata`; it gains `konventix_brand_id`,
   `konventix_brand_name` and `konventix_operator_id`, set on the
   PaymentIntent too (`payment_intent_data.metadata`) so it survives onto
   the charge, the balance transaction and the Stripe exports. With that,
   the Stripe dashboard and every report can be filtered and split per
   brand without a second account; `description` names the brand in plain
   words for the rows that show no metadata. ADR-0040's bookkeeping export
   is **unchanged**: it books documents, not payments, per brand already.

5. **The webhook endpoint stops being a Connect endpoint.** One endpoint
   per environment, the same URL (`/api/commerce/webhooks/stripe`) and the
   same seven events (`StripeWebhookEvents::ALL`), created **without**
   "Listen to events on connected accounts". Signature verification,
   idempotency per `(provider, external_id)`, ADR-0039 point 2 and
   ADR-0057 point 7 are all unchanged — this is the one piece of the Stripe
   integration eventula does not have, and it is the piece worth keeping.

6. **Nothing about the payment mechanics changes.** Hosted Checkout Session
   only, no card fields on Konventix pages, no Stripe.js, SAQ A intact
   (ADR-0039 point 1) — deliberately *not* eventula's Elements page.
   `StripeSettlementAdapter`, `Payment`, `RecordPaymentSuccess`,
   `RecordPaymentReversal`, `RefundOrder` and the reconciliation keep their
   shape; only the account argument disappears.

7. **PayPal is a payment method inside Stripe or Mollie, never its own
   adapter.** Konventix does not gain a native PayPal `SettlementAccount`
   provider; where a payer is offered PayPal, it is a payment method
   Stripe's or Mollie's hosted Checkout enables, settled and reconciled
   like every other method those two providers carry — matching eventula's
   own history, where PayPal was never a code path at all.

8. **#161 is closed by this ADR, not implemented.** "Own keys as an
   alternative to Connect" becomes "own keys, full stop"; its acceptance
   criteria about a per-account key, webhook route and signing secret are
   dropped with it, since one account per instance leaves nothing to key
   per account. #198 and #199, written against the onboarding flow this ADR
   removes, become obsolete for the same reason; #215 tracks the
   implementation.

9. **Migration.** Existing Stripe rows on dev carry a `provider_account_id`
   and a `capabilities` blob that simply stop being read; production has no
   connected account yet, which is why this decision is cheap *now* and
   would be expensive after 22.09. A migration nulls `provider_account_id`
   and `capabilities` for `provider = 'stripe'` rows so no stale account id
   is picked up by an old code path, and drops nothing — the columns stay
   for Mollie/Unzer and for history. The sandbox connected accounts on dev
   are abandoned at Stripe, nothing to delete. Any Stripe row whose
   onboarding never completed (`connected_at IS NULL`) is marked connected
   by the same migration: with no onboarding, "connected" is what every
   Stripe row now is.

### Alternatives considered

**Keep Connect and add the key mode beside it (#161 as written).** Two
connection shapes on one provider forever: two client constructions
(platform key + header vs. own key), two webhook shapes (one platform
endpoint for connected events, one route per key account with its own
secret), a mode flag visible in the console, the adapter, the
reconciliation and the capability sweep, and every Stripe test written
twice. It buys the ability to onboard a *third party's* Stripe account —
the marketplace case the operator explicitly rejects. Rejected: it is the
larger build and it preserves the thing that is wrong. A narrower variant,
connecting the operator's *existing* account to itself, fares no better:
Stripe's v2 Accounts API only creates *new* accounts, so reaching an
existing one needs OAuth Connect, a Connect application review and an
onboarding trip that ends where it started — all to reach the state one
secret key already describes.

**One Stripe account per brand, entered by key.** Removes Connect but keeps
the per-brand account. The operator has said plainly that this is not a
viable setup: a single e.V. would need N identity verifications, N bank
accounts and N payout schedules for what is one business. Rejected — and
point 4 shows the reporting need behind it is met by metadata.

**Keys in the environment only (no console form).** Simplest, and it is
what the chart already does. Rejected as the *only* option because the
operator asked to enter them in the console, and because a key rotation
before the 22.09 cutover should not need a redeploy and a `config:clear`.
Kept as the fallback half of point 2 — env remains fully supported and is
what a fresh deployment uses.

**Keys per brand on `SettlementAccount.credentials`, like Mollie.** It is
the smaller change (the column and the encryption exist), but it re-states
in data exactly the multi-seller assumption this ADR removes, and it would
have the operator paste the same key into every brand. Rejected for
Stripe; it stays the shape for Mollie and Unzer today (see Consequences).

## Consequences

- **Removed:** `StripeApi::createConnectedAccount()`, `createOnboardingLink()`,
  `fetchAccount()` and their SDK implementations; the `$stripeAccountId`
  parameter on every remaining `StripeApi` method;
  `SettlementAccountAdminController::stripeReturn()` and its route;
  `RefreshSettlementAccountCapabilities`' Stripe arm and its schedule
  entry; the Stripe branch of `isOffered()`; the "Onboarding pending"
  badge and the contact-email field in the console. `connectStripe()`'s
  `Inertia::location()` goes too, incidentally removing the C10
  unsaved-changes prompt at its source. #161, #198 and #199 close as
  superseded/obsolete rather than as implemented.
- **Added:** an operator-level Stripe credentials row (secret, webhook
  secret, optional publishable key) and its migration, a console card to
  set and clear it (with a `sk_live_` on a non-production instance warning,
  ADR-0053), the resolution seam in the `StripeApi` binding and in
  `StripeWebhookController`, `statement_descriptor_suffix` on
  `SettlementAccount` with its 22-character validation, and the
  brand/operator metadata on the session and the payment intent.
- `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET` and the chart's
  `secrets.payments` group keep their names; their meaning changes from
  "the platform's Connect pair" to "this instance's Stripe account, unless
  the operator has entered its own". `docs/helm-chart-requirements.md` and
  the README lose the "Listen to events on connected accounts"
  instruction, the single most common Connect misconfiguration.
- ADR-0007 keeps its ruling and loses its mechanism: the organizer's *own*
  account, reached through Connect Standard, is replaced by "the operator
  running the instance is the merchant". ADR-0039 point 5 is rewritten by
  this ADR; points 1-4 stand, except that Stripe's credentials custody
  moves from "platform, from env" to "operator, from row or env". ADR-0046
  keeps its rule unchanged — one live account per provider per brand, the
  payer picks — but a Stripe row is now an enablement flag, not an account
  of its own. ADR-0057 is untouched.
- PayPal orders taken over from eventula-manager during the cutover are
  historical: eventula never routed PayPal through a settlement adapter, so
  those orders are accepted as a cutover gap rather than reconciled through
  this ADR's model (#162, #146).
- Mollie and Unzer keys stay brand-level for now (ADR-0039 point 4); moving
  them to operator-level credentials the same way this ADR moves Stripe's
  is a follow-up, not a decision made here.
- Tests: `SettlementAccountAdminTest` loses the onboarding and return cases
  and gains "enabling Stripe needs no Stripe call" and "a brand is not
  offered Stripe while no key is configured"; `StripeAdapterTest`,
  `StripeWebhookTest`, `PaymentReconciliationTest`,
  `MultiProviderSettlementTest` and `SettlementAccountFactory` drop the
  account id; new: key resolution row-before-env, a live key warning on a
  non-production instance, the 22-character suffix validation, and
  metadata reaching the session and the intent.
- **Timing is the whole point.** Production has no connected account and no
  money has moved through one. After the 22.09 cutover every live payment,
  refund and reversal would hang off a connected account id, and this
  becomes a migration with money in it. Decided before the cutover it is a
  deletion.
- Left open on purpose: whether an instance should ever hold more than one
  Stripe account (it should not); and per-brand payout separation, which
  Stripe does not offer without Connect and which point 4's metadata
  answers for reporting but not for banking.
