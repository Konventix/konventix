# Commerce / Registration / Admission — LanCore inventory

What each LanCore capability in the commercial and access-rights surface does
today, and where it lands under the Registration / Admission / Commerce split.
This is the highest-stakes boundary in the port: LanCore fused the commercial
act and the access right into one `Ticket` row and let payment imply
confirmation. ADR-0005, ADR-0006 and ADR-0007 exist to break that fusion, so
almost nothing here ports unchanged — the interesting column is **Verdict**,
and the interesting section is **Open decisions**.

## Sources

**LanCore SRS families** (`docs/mil-std-498/SRS.md`)

- `TKT-F-001..029` — Ticketing (CSCI-TKT), §3.2.2
- `SHP-F-001..020` — Shop (CSCI-SHP), §3.2.3, plus `SHP-F-021` cited in code
  (provider fee capture) but absent from the SRS table
- `CTD-F-001..002` — Countdown (CSCI-CTD), §CC, tracing to `CAP-CTD-001..002`
- Supporting: `IF-JWKS-001..006` and `IF-VALIDATE-001..006` (IRS §3.12/§3.13),
  `NTF-F-008..013` (ticket-sale notifications), `SET-F-011` (consent
  withdrawal, explicitly distinguished from `SHP-F-010/011`), OCD §5 and §7
  (entrance flow, LanEntrance, key rotation, threat model)

**LanCore code actually read**

- `app/Domain/Ticketing/` — `Models/{Ticket,TicketType,TicketCategory,TicketGroup,Addon,EntranceAuditLog}.php`,
  `Actions/UpdateTicketAssignments.php`, `Http/Controllers/{TicketController,AdminTicketController}.php`,
  `Http/Controllers/Api/{EntranceController,SigningKeysController}.php`,
  `Security/` (TicketTokenService, TicketKeyRing, IssuedToken, exceptions),
  `Enums/{TicketStatus,CheckInMode,TicketSalePhase,Permission}.php`,
  `Jobs/{GenerateTicketPdf,DispatchTicketSaleReleaseJob,DispatchTicketSaleEndJob}.php`,
  `Notifications/`, `Gdpr/TicketingDataSource.php`
- `app/Domain/Shop/` — `Models/{Order,OrderLine,Cart,CartItem,Voucher,PurchaseRequirement,GlobalPurchaseCondition,PaymentProviderCondition,CheckoutAcknowledgement,ShopSetting}.php`,
  `Actions/{CreateOrder,CreateCheckoutSession,FulfillOrder,GenerateInvoiceNumber,PersistProviderFee}.php`,
  `Http/Controllers/{CartController,ShopController,OrderController,UserOrderController,ShopSettingsController,PayPalWebhookController}.php`,
  `PaymentProviders/{PaymentProviderManager,StripePaymentProvider,OnSitePaymentProvider,PayPalPaymentProvider}.php`,
  `Contracts/{Purchasable,PurchasableDependency,PaymentProvider,ResolvesProviderFees}.php`,
  `Concerns/InteractsWithShop.php`, `Support/{CurrencyResolver,ProviderFee,ProviderFeeSchedule}.php`,
  `Jobs/{GenerateInvoicePdf,GenerateReceiptPdf}.php`, `Gdpr/ShopDataSource.php`,
  `Enums/{OrderStatus,PaymentMethod,VoucherType,Currency,Permission}.php`
- `routes/shop.php`, `routes/ticketing.php`, `routes/api-integrations.php`
- `app/Http/Controllers/Settings/TicketDiscoveryController.php`,
  `app/Http/Controllers/CountdownController.php`

**Konventix targets**

`app-modules/registration/SPEC.md`, `app-modules/admission/SPEC.md`,
`app-modules/commerce/SPEC.md`, `docs/architecture/module-map.md`,
`docs/lessons-from-lancore.md`, ADR-0004/0005/0006/0007/0009/0010/0013/0014.

## Feature inventory

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Ticket type offer (name, description, price, sale window) | TKT-F-001 | One `TicketType` row is simultaneously the product, the price, the capacity limit and the implicit promise "one person may enter". Two nullable datetimes (`purchase_from` / `purchase_until`) gate `isAvailableForPurchase()` | Registration | `RegistrationType` | Generalize |
| `quota` column on the type | TKT-F-001 | Integer on `ticket_types`; availability is `tickets()->count() < quota`, i.e. a live COUNT per read | Registration | `Quota` (standalone entity) | Generalize |
| `max_per_user` purchase limit | TKT-F-001 | Caps quantity per buyer, evaluated in `getMaxQuantity()` at cart time only | Registration | `max_per_registrant` on `RegistrationType` | Port renamed |
| `is_hidden` flag | TKT-F-001 | Boolean hides a type from the public shop but leaves it buyable by direct POST | Registration | `visibility` axis (`public` / `link` / `invite_only`) — ADR-0005 | Generalize |
| `is_locked` on TicketType | TKT-F-011 | Set to `true` by `FulfillOrder` on first sale; blocks concurrent edits to a type that already sold | Registration | Sales lock / immutable-after-first-sale on `RegistrationType` | Port renamed |
| Ticket categories (grouping + `sort_order`) | TKT-F-002 | Display grouping of ticket types within an event | Registration | `RegistrationTypeCategory` | Port renamed |
| Ticket groups (second grouping mechanism) | TKT-F-003 | A parallel, near-identical grouping with no distinct semantics; `TicketType` carries both `ticket_category_id` and `ticket_group_id` | — | — | Drop |
| Group ticket types (`max_users_per_ticket`) | TKT-F-013 | One ticket row covers N people via a pivot; the workaround for `1 ticket = 1 gamer` | Registration | Grant definition: one Registration grants N Admissions (ADR-0005) | Generalize |
| Seat consumption `seats_per_user × max_users_per_ticket` | TKT-F-016 | Computes and reserves venue seat capacity at purchase time, checked against `Event::remainingSeatCapacity()` | Registration + Resources | Resource claim on the grant definition; booking in Resources | Split across modules |
| `is_row_ticket` / `is_seatable` booleans | TKT-F-009 (deprecated) | Marks a type as tied to a seat plan / row | Resources | Resource-claim kind on the grant definition | Drop |
| Add-ons (price, quota, `seats_consumed`, `requires_ticket`) | TKT-F-007 | A second purchasable type, event-scoped, purchasable only alongside a ticket | Registration or Commerce | undecided — see **D-COM-10** | Split across modules |
| Add-on price history via `ticket_ticket_addon.price_paid` | TKT-F-007 | Snapshots the price paid onto the ticket↔addon pivot, plus `order_id` | Commerce | Order line price snapshot | Port renamed |
| Ticket-type / category / add-on audit trails | TKT-F-012 | `owen-it/laravel-auditing` on the models, plus three dedicated audit controllers and views | Compliance (+ Registration UI) | Audit log | Split across modules |
| Admin CRUD for types, categories, add-ons | TKT-F-010 | Full resource controllers with index/create/edit/store/update/destroy and Store*/Update* form requests | Registration | Registration-type administration | Port renamed |
| Ticket-sale release / end notification scheduling | TKT-F-001, NTF-F-008..013 | `notify_on_release`, `notify_on_end`, `notify_on_end_lead_minutes`, `release_notified_at`, `end_notified_at` on the type; two jobs fan out to opted-in users, suppressing existing holders | Registration + Messaging | Registration opens/closes announcement | Split across modules |
| Public shop catalogue page | SHP-F-001 | `ShopController@index` lists visible ticket types and add-ons with `remaining_quota` and a human `unavailability_reason` — but only for **one** event, hardcoded as `Event::published()->upcoming()->first()` | Registration | Per-event registration offer listing | Generalize |
| Cart, one per user per event | SHP-F-001 | `Cart::forUser()`; `event_id` is nulled when the cart empties, so a user can only ever shop one event at a time. `GET cart/count` feeds the header badge | Commerce | see **D-COM-07** | Generalize |
| Polymorphic cart items via `Purchasable` | SHP-F-002 | `CartItem` morphs to `TicketType` or `Addon`; `InteractsWithShop` supplies title/price/line-item defaults | Commerce | Cart line referencing an offer by ULID + type key (no cross-module morph — ADR-0004) | Generalize |
| Cart dependency validation | SHP-F-002 | `PurchasableDependency` — "this add-on requires a ticket in your cart or account" — evaluated in `Cart::validateDependencies()` | Registration | Offer prerequisite rule | Port renamed |
| Cart voucher apply / remove | SHP-F-007 | `cart.voucher_code` is a loose string on the cart; re-resolved and re-validated at every render | Commerce | Voucher application on the cart | Port renamed |
| Voucher entity (fixed / percentage) | SHP-F-007 | `code`, `type`, `discount_amount`, `discount_percent`, `max_uses`, `times_used`, validity window, `is_active` | Commerce | `Voucher` | Generalize |
| Voucher event scoping | SHP-F-008 | `voucher.event_id` nullable; null = valid everywhere, otherwise must match the order's event | Commerce | Voucher scope (brand / event / registration type) | Generalize |
| Voucher pre-validation endpoint | SHP-F-008 | `POST shop/voucher/validate` returns validity + computed discount before checkout | Commerce | Voucher preview | Port as-is |
| Voucher redemption counter | SHP-F-007 | `FulfillOrder` does `$order->voucher->increment('times_used')` — no per-redemption record, no reversal on refund | Commerce | `VoucherRedemption` records + `VoucherRedeemed` event | Generalize |
| Voucher admin CRUD + audit view | SHP-F-007, SHP-F-014 | Resource controller plus a dedicated audit trail page | Commerce | Voucher administration | Port renamed |
| Purchase requirements | SHP-F-009 | Per-purchasable acknowledgement blocks: rich `requirements_content`, a JSON `acknowledgements` array, `requires_scroll`; attached to ticket types and add-ons via a morph pivot | Registration or Compliance | see **D-COM-11** | Split across modules |
| Global purchase conditions | SHP-F-010 | Ordered, active-flagged terms/privacy blocks shown at every checkout; also rendered onto the ticket PDF | Compliance | Policy (Compliance-owned) | Generalize |
| Payment-provider-specific conditions | SHP-F-010 | Same shape as global conditions, but filtered by `PaymentMethod` | Commerce | Settlement-method terms | Port renamed |
| Checkout acknowledgement records | SHP-F-011 | `CheckoutAcknowledgement` (user, morph target, key, timestamp), written via an XHR `cart/acknowledge` call before checkout | Compliance | Consent record | Generalize |
| Checkout review step | SHP-F-010, SHP-F-011 | `reviewCheckout` re-computes totals and gathers all three condition sources for the chosen payment method | Commerce | Checkout review | Port renamed |
| Order + order lines | SHP-F-006 | `Order` (subtotal / discount / total, currency, user, event, voucher) with morph-typed `OrderLine` rows | Commerce | `Order` + `OrderLine`, referencing what they settle by ULID (ADR-0006) | Generalize |
| Order statuses | SHP-F-006 | Enum is `pending / completed / failed / refunded` — the SRS also claims `cancelled`, which does not exist in the enum | Commerce | Order status + separate payment status | Generalize |
| Order `metadata` JSON as the fulfilment instruction | SHP-F-016 | A JSON blob of `{ticket_type_id, quantity, addon_ids}` on the order; `FulfillOrder` decodes it to decide what to create | Commerce | Typed order lines are the only instruction (ADR-0009) | Drop |
| `FulfillOrder` creating tickets | SHP-F-012, SHP-F-016 | One action sets the order complete, stamps `paid_at`, locks the ticket type, creates N `Ticket` rows, attaches add-ons, mints tokens, dispatches PDFs, notifies and fires `TicketPurchased` | Registration + Admission + Commerce | Commerce emits `OrderPaid`; Registration confirms; Admission issues (ADR-0006) | Split across modules |
| Idempotent fulfilment (return URL + webhook) | SHP-F-015, SHP-F-016 | Guarded by an early `status === Completed` return inside `FulfillOrder` | Commerce | Idempotent settlement handling | Port renamed |
| Stripe Checkout via Cashier | SHP-F-003 | `CreateCheckoutSession` builds Stripe line items and calls `$user->checkout(...)`; Stripe holds the session truth | Commerce | Stripe settlement adapter, on this instance's own account (ADR-0058) | Generalize |
| Stripe `checkout.session.completed` webhook | SHP-F-015 | `HandleStripeCheckoutCompleted` fulfils the order when the buyer never returns | Commerce | Adapter webhook → `PaymentReceived` | Port renamed |
| PayPal Orders v2 | SHP-F-019 | `PayPalPaymentProvider` via `srmklive/paypal`, create + capture | Commerce | PayPal settlement adapter | Defer post-release |
| PayPal webhook with local verification | SHP-F-020 | `PAYMENT.CAPTURE.COMPLETED` verified via `verifyWebHookLocally` and `PAYPAL_WEBHOOK_ID` | Commerce | PayPal adapter webhook | Defer post-release |
| On-site payment provider | SHP-F-004 | Order stays `pending` with `paid_at = null`; an admin (or the door client) later confirms and triggers fulfilment | Commerce | Manual payment booking (first-class, ADR-0006) | Generalize |
| `PaymentProviderManager` factory | SHP-F-005 | In-memory registry of providers keyed by `PaymentMethod`, filtered by an admin enable/disable map | Commerce | `SettlementAdapterRegistry` | Port renamed |
| Provider fee capture | SHP-F-021 (code only) | `PersistProviderFee` snapshots `fee_amount`, `net_amount`, `fee_source`, `fees_fetched_at`; `ProviderFeeSchedule` estimates from config until the webhook confirms | Commerce | Settlement fee snapshot | Port as-is |
| Admin payment-method enable/disable | SHP-F-005 | `shop_settings.enabled_payment_methods` JSON map, enforced in a closure validator at checkout | Commerce | Per-brand settlement configuration | Generalize |
| Shop currency setting + order snapshot | SHP-F-018 | Single instance-wide `shop_settings.currency` via `CurrencyResolver`; orders snapshot it to `orders.currency` | Commerce | Per-brand currency + order snapshot | Generalize |
| `shop_settings` key/value JSON store | SHP-F-018 | Schemaless `key` → JSON `value` table holding currency, invoice prefix, footer, notes, enabled methods and the invoice counter | Commerce (+ Platform) | Typed per-brand settings objects (ADR-0009, lesson 11) | Generalize |
| Invoice number generation | SHP-F-006 | `GenerateInvoiceNumber` reads/increments `shop_settings.invoice_counter_<year>` with no locking — a read-modify-write race, instance-wide, not per brand | Commerce | Per-brand `InvoiceNumberRange` (ADR-0006) — see **D-COM-05** | Generalize |
| Invoice PDF generation + mail | — | `GenerateInvoicePdf` renders DomPDF from `pdf.invoice`, stores to the private disk, mails it; no immutability, no archival guarantee | Commerce | `Invoice` document | Generalize |
| Receipt PDF generation + mail | — | `GenerateReceiptPdf`, same shape, for paid orders. Both documents are re-served later from the private disk via `OrderPolicy`-gated streamed downloads | Commerce | Payment receipt | Port renamed |
| Order confirmation notification | SHP-F-012 | `OrderConfirmationNotification` dispatched inline from `FulfillOrder` | Messaging | Order confirmation message | Split across modules |
| Admin order list / detail | SHP-F-006 | Filterable index (`OrderIndexRequest`) plus detail view with lines and tickets | Commerce | Order administration | Port renamed |
| Admin "confirm payment" for on-site orders | SHP-F-004 | `orders/{order}/confirm-payment` stamps `paid_at` and `confirmed_by`, then fulfils | Commerce | Manual payment booking | Port renamed |
| "My orders" view | SHP-F-017 | User-facing list and detail with lines and the tickets each order produced | Commerce | My orders | Port renamed |
| `TicketPurchased` event | SHP-F-012 | Carries the `User` and `Order` **Eloquent models**; consumed by webhook fan-out | Commerce | `OrderPaid` with ULIDs and scalars only (ADR-0004, ADR-0006) | Generalize |
| `CartItemAdded` event | SHP-F-013 | Fired on add-to-cart, consumed by nothing in the domain | — | — | Drop |
| Shop policies | SHP-F-014 | Five policies (Order, Voucher, PurchaseRequirement, GlobalPurchaseCondition, PaymentProviderCondition) driven by a static role→permission map | Commerce | `Commerce\Enums\Permission` + per-brand role data (ADR-0013) | Port renamed |
| Shop GDPR data source | — | Exports orders + lines, carts + items, and checkout acknowledgements | Commerce | `Commerce\Gdpr\` exporter | Port as-is |
| Ticket entity and statuses | TKT-F-004 | `Ticket` with `active / checked_in / cancelled`; also the buyer's contract row, the invoice line reference, the seat holder and the QR credential — all at once | Admission | `Admission` (the access right) — Registration keeps the contract | Split across modules |
| Ticket owner / manager / user roles | TKT-F-006, TKT-F-014 | `owner_id` and `manager_id` columns plus a `ticket_user` pivot; owner ≈ buyer, manager ≈ delegate, users ≈ the people who may enter | Registration + Admission | Registrant / responsible party live in Registration; holder is one Admission per person | Split across modules |
| `ticket_user` pivot (multi-user ticket) | TKT-F-014 | N people share one ticket row and one QR code | Admission | One `Admission` per person, each with its own token | Drop |
| Check-in mode (`individual` / `group`) | TKT-F-015 | Per-type flag deciding whether a scan admits one person or the whole group | Admission | Check-in mode on the admission group | Generalize |
| Unnamed group members | TKT-F-014 | Members are added by the manager after purchase; until then the ticket has holders it cannot name | Registration or Admission | see **D-COM-09** | Split across modules |
| Ticket discovery privacy setting | — | `users.is_ticket_discoverable` + `ticket_discovery_allowlist`; controls whether a manager can find you by name/email to add you to a ticket | Identity (+ Admission read model) | Attendee discoverability | Port renamed |
| Validation ID per ticket | TKT-F-005 | Original opaque per-ticket identifier, superseded by the signed token | — | — | Drop |
| LCT1 signed ticket token | TKT-F-017 | `LCT1.<kid>.<body>.<sig>`, Ed25519, body `{tid, nonce, iat, exp, evt}`; embedded in the QR | Admission | `KVX1` admission token | Generalize |
| Deterministic nonce + stored hash | TKT-F-018 | `validation_nonce_hash` (HMAC-SHA256 with a pepper, UNIQUE), `validation_kid`, issued/expires, `validation_rotation_epoch`; plaintext nonce and full token are never stored | Admission | Token metadata on `Admission` | Port as-is |
| Rotation on exactly four write paths | TKT-F-019 | Issuance, manager change, add user, remove user, explicit rotate — never on a read path | Admission | Token rotation policy | Port renamed |
| Token clearing on cancellation | TKT-F-020 | Nulls hash/kid/timestamps, making previously printed QRs unresolvable | Admission | Token revocation on admission revoke | Port as-is |
| JWKS signing-keys endpoint | TKT-F-021, IF-JWKS-001..006 | `GET /api/entrance/signing-keys`, Bearer-authenticated, active + retired-unexpired keys, `Cache-Control` TTL | Admission | Entrance key set | Port as-is |
| Key rotation command | TKT-F-022 | `tickets:keys:rotate` generates an Ed25519 pair, writes the private key to `storage/keys/ticket_signing/{kid}.key`, marks it active | Admission | `admission:keys:rotate` | Port renamed |
| Structured validation error codes | TKT-F-023, IF-VALIDATE-002..005 | `invalid_signature`, `unknown_kid`, `expired`, `revoked` alongside `valid` / `already_checked_in` / `invalid` | Admission | Check-in decision codes | Port as-is |
| Self-service token rotation | TKT-F-024 | `POST tickets/{ticket}/rotate-token`, owner or manager, 10/min — lets a holder invalidate printed copies | Admission | Self-service token rotation | Port renamed |
| Token-rotated notification | TKT-F-025 | Security notification to owner + all attached users + the removed user + the previous manager; mail + database, explicitly not suppressible | Admission + Messaging | Admission token rotated | Split across modules |
| Non-rotating QR and PDF render | TKT-F-026 | `tickets/{ticket}/qr` re-derives the nonce from the stored epoch and re-signs; `tickets/{ticket}/download` serves the cached PDF or regenerates it — neither ever rotates | Admission | Credential rendering and download | Port as-is |
| Tri-fold A4 ticket PDF | TKT-F-027 | Three 99 mm panels: event hero, QR + holder data, all active `GlobalPurchaseCondition` rows as legal text plus the org footer | Admission | Admission document / badge — theming via Platform tokens | Generalize |
| Forensic watermark on the PDF | TKT-F-029 | Holder name, event, venue, org, type and ticket ID at ~12% opacity, diagonally repeated, deterministically seeded, with a clear rectangle around the QR | Admission | Document watermark | Port as-is |
| Bulk token rotation command | TKT-F-028 | `tickets:rotate-all`, with `--only-legacy`; a one-off migration aid for the deterministic-nonce deploy | — | — | Drop |
| Entrance validate endpoint | IF-VALIDATE-001 | Parses and verifies the token, resolves the ticket, checks status, event match, event end date — and payment | Admission | Entrance validate | Generalize |
| Entrance payment gate at the door | IF-VALIDATE-006 | Returns `payment_required` with an amount and line items when the ticket's order is on-site and unpaid | — | see **D-COM-08** | Split across modules |
| Entrance check-in endpoint | TKT-F-004 | Re-verifies then delegates to `UpdateTicketAssignments::checkIn`, flipping the ticket to `checked_in` | Admission | Entrance check-in, producing a `CheckIn` record | Generalize |
| Entrance verify-check-in endpoint | — | Byte-identical to check-in apart from the audit action string | — | — | Drop |
| Entrance payment confirmation | SHP-F-004 | Compares a formatted amount string, stamps `paid_at` + `confirmed_by`, dispatches a receipt, then checks in | Commerce (+ Admission) | Door payment booking — see **D-COM-08** | Split across modules |
| Entrance override with reason | — | Forces a check-in with a mandatory 10–500 char reason, logged | Admission | Supervisor override | Port renamed |
| Entrance attendee search | — | `ilike` name/email search across owner and attached users, returning a 4-char token-hash suffix as a manual cross-check | Admission | Entrance attendee lookup | Generalize |
| Entrance events list | — | Lists non-ended events so the door client can pick one | Admission (Event read model) | Entrance event selection | Port renamed |
| Entrance stats | — | Aggregates over the audit log: scans, check-ins, denials, overrides, payments collected, scans per hour; `avg_checkin_time_ms` is hardcoded to 0 | Admission | Entrance dashboard | Port renamed |
| Entrance audit log | — | `EntranceAuditLog`: ticket, 16-hex token fingerprint, action, decision, operator, session, client info, override reason, metadata | Admission | Check-in log (attendance data — GDPR-relevant) | Generalize |
| LanEntrance companion app | OCD §5, §7 | Separate app, Bearer integration token, caches JWKS, does a local signature pre-check, then always calls the authoritative endpoint — **it is not actually offline-capable** | Admission | Entrance client — see **D-COM-06** | Generalize |
| Bearer integration-token auth for the entrance API | IF-JWKS-001, IF-JWKS-005 | One shared token per registered integration app, no per-device identity or revocation story | Admission (+ Platform) | Per-device entrance credentials | Generalize |
| "My tickets" list and detail | TKT-F-006 | Index and detail of tickets owned, managed or attached to, with add-ons and seat | Admission | My admissions | Port renamed |
| Manager change / add user / remove user | TKT-F-006, TKT-F-019 | `UpdateTicketAssignments` mutates the assignment set and rotates the token on every change | Admission | Holder assignment and reassignment | Generalize |
| Admin ticket list / detail / force-rotate | TKT-F-010 | Filterable admin index, detail, and an admin-triggered token rotation | Admission | Admission administration | Port renamed |
| Ticket policies | TKT-F-008 | `TicketPolicy`, `TicketTypePolicy`, `TicketCategoryPolicy`, `AddonPolicy`, backed by `ManageTicketing` and `CheckInTickets` | Admission + Registration | Split capability catalogs — door staff get scan + print only | Split across modules |
| Seat release on ticket cancellation | SET-F-008 | A model observer deletes `SeatAssignment` rows when a ticket becomes `cancelled` — a cross-domain write from a model boot hook | Resources | Resources listens for `AdmissionRevoked` (ADR-0004) | Split across modules |
| Ticketing GDPR data source | — | Exports tickets (with co-users obfuscated) and seat assignments together | Admission (+ Resources) | Per-module `Gdpr/` exporters | Split across modules |
| Refunds | SHP-F-006 | **Only an unused `OrderStatus::Refunded` enum case.** No action, no controller, no route, no PSP call, no voucher or quota reversal | Commerce | Refund and credit-note flows — see **D-COM-04** | Generalize |
| Order cancellation | SHP-F-006 | The SRS names a `cancelled` status; the enum does not have one and nothing cancels an order | Commerce + Registration | Order cancellation vs. registration withdrawal, as distinct acts | Generalize |
| Public countdown page | CTD-F-001 | Unauthenticated page resolving the next upcoming published event and rendering a live timer | Platform (Content) | Countdown block for brand pages | Split across modules |
| Countdown newsletter signup | CTD-F-002 | Embeds a signup form posting to the default public Listmonk list; hidden when no default list exists | Newsletter | Public signup block | Defer post-release |

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| `Ticket` | `Registration` + `Admission` | The single most important split in the port. One row cannot be the contract, the invoice line, the seat holder and the QR code without making every rule about one of them accidentally true of the others. Two objects mean the door can ask "is this admission valid?" without ever loading an order. |
| `TicketType` | `RegistrationType` | "Ticket" pre-decides that the thing sold is one person's entry. A speaker slot, a booth, and a crew signup are all registration types; none of them is a ticket. The explicit axes (ADR-0005) exist because the moment the name stops implying "entry", the implicit assumptions have to become fields. |
| `owner` / `manager` / ticket `users` | Registrant / responsible party / admission holder | Three roles on one row is why "who may edit this" and "who may enter" were the same question. Splitting the names splits the authorization. |
| `ticket_user` pivot | One `Admission` per person | N people sharing one QR is a workaround, not a model. Per-person admissions make revocation, badge printing and check-in per person free instead of special-cased. |
| `quota` (column) | `Quota` (entity) | A column can only ever mean "this type's own limit". An entity can be shared by several types, released on cancellation, and reported on — and it stops availability from being a `COUNT(*)` on every page render. |
| `Addon` | Offer / extra (module TBD) | "Add-on" is defined relative to a ticket. Once the ticket is gone the name has no referent, which is exactly why **D-COM-10** has to be answered. |
| `TicketGroup` (dropped) + `TicketCategory` | `RegistrationTypeCategory` | Two grouping mechanisms with the same shape means neither has semantics. Keeping one forces the question "what is this grouping *for*" to be answered once. |
| `PaymentMethod` / `PaymentProvider` | Settlement method / settlement adapter | "Payment provider" invites the provider to be authoritative — which is how Stripe ended up owning order truth. "Adapter" is a role you can replace; the domain model leads (ADR-0006). |
| `TicketPurchased` (event carrying models) | `OrderPaid` (ULIDs and scalars) | The old name asserts a ticket exists because money moved. `OrderPaid` states only what Commerce knows; whether that confirms anything is `confirmation_gate`'s decision (ADR-0005). |
| `OrderStatus::Completed` | Order status **and** payment status, separately | One enum conflated "we are done with this order" with "the money arrived". An invoice-settled exhibitor order is placed, confirmed and unpaid at the same time, and that has to be expressible. |
| `orders.confirmed_by` | Payment booked by | The column records who confirmed *payment*, but the name reads as who confirmed the *order* — the exact conflation ADR-0005 removes. |
| `GlobalPurchaseCondition` | Policy (Compliance) | Calling terms and privacy "purchase conditions" hides them inside checkout, so a free registration never shows them and there is nowhere to withdraw consent. As policies they are one concept with one consent record (see `SET-F-011`). |
| `CheckoutAcknowledgement` | Consent record | Same reason: consent is a Compliance concept that happens to be collected at checkout, not a checkout artefact. |
| `LCT1` token | `KVX1` admission token | Version-prefixed by design; the rename is the natural moment to make the payload admission-scoped rather than ticket-scoped. |
| `EntranceAuditLog` | Check-in log | "Audit log" invites it to be treated as infrastructure. It is attendance data about identified people and needs a retention answer and a GDPR exporter. |
| `shop_settings` (key/value JSON) | Typed per-brand commerce settings | Magic-string access to a JSON blob is lesson 11; making it typed also makes it brand-scopable, which the invoice counter urgently needs. |
| `is_hidden` | `visibility` axis | A boolean cannot express "reachable by link but not listed", which is exactly what an invite code or a press link needs. |
| `Seat` consumption on a ticket type | Resource claim in the grant definition | Seats are one resource type with a time dimension; naming the claim generically is what lets booths, rooms and devices reuse it. |

## Dropped / deferred, and why

**Dropped outright**

- **`TicketGroup` (TKT-F-003).** A second grouping mechanism identical in shape
  to `TicketCategory`, with no distinguishing behaviour anywhere in the code.
  One grouping concept survives.
- **`ticket_user` pivot (TKT-F-014).** Deliberately superseded: one Admission
  per person is the whole point of ADR-0005. The *capability* it provided —
  buying N entries in one act — survives as grant definitions.
- **`is_row_ticket` / `is_seatable` (TKT-F-009).** Already deprecated in
  LanCore. Seat coupling becomes a resource claim in Resources.
- **`TicketValidationId` (TKT-F-005).** Superseded by the signed token before
  the port even starts.
- **`tickets:rotate-all` (TKT-F-028).** A one-time migration command for
  LanCore's deterministic-nonce deploy. Konventix issues tokens correctly from
  the first one; a bulk-rotate operational tool may be added later, but not
  this command and not for this reason.
- **Entrance `verify-checkin` endpoint.** Byte-for-byte identical to `checkin`
  except for the audit label. If a two-person verification flow is wanted it
  should be a flag on check-in, not a duplicate endpoint.
- **`CartItemAdded` event (SHP-F-013).** Emitted, consumed by nothing.
- **Order `metadata` JSON as fulfilment instruction (SHP-F-016).** Typed order
  lines carry this (ADR-0009). The blob also silently limits an order to
  ticket types and add-ons.
- **Cross-module model observers.** The `Ticket::booted` hook that deletes
  `SeatAssignment` rows becomes a Resources listener on `AdmissionRevoked`
  (ADR-0004). Same effect, legal direction.
- **Instance-wide singletons.** Single shop currency, single invoice counter,
  single "next upcoming event" shop. All become per-brand (ADR-0001, ADR-0010).

**Deferred to after release**

- **PayPal adapter (SHP-F-019, SHP-F-020).** Stripe plus manual bank transfer
  covers the release scope. ADR-0058 point 7 has since answered the deferral for
  good: PayPal is a payment method inside Stripe's or Mollie's hosted checkout,
  never a settlement provider of its own.
- **Countdown newsletter signup (CTD-F-002).** Newsletter is explicitly a
  post-release module in the module map. The countdown *timer* (CTD-F-001)
  survives as a Platform content block; the signup form comes with Newsletter.
- **Bank reconciliation (CAMT.053 / MT940).** Manual payment booking is
  first-class (ADR-0006) and sufficient at release.
- **Instalment plans for exhibitor invoices.** Dunning against a single due
  date first; payment plans need their own state machine.
- **E-invoicing formats (ZUGFeRD / XRechnung).** Real requirement for B2B
  exhibitor business in DACH, but it presupposes the invoice document model is
  settled. Sequenced after **D-COM-05**.
- **Multi-currency per brand.** Currency snapshots on the order from day one so
  the door is open; a brand selling in two currencies is not release scope.

**Explicitly not inherited (LanCore never built it)**

- **Refunds.** An unused enum case is the entire implementation. Konventix has
  to design this rather than port it — see **D-COM-04**.
- **Order cancellation.** Named in the SRS, absent from the enum and the code.
- **Credit notes, dunning, payment terms, due dates.** None exist. All are new
  Commerce domain model (ADR-0006).
- **Approval workflows, invitation tokens, waiting lists.** No analogue in
  LanCore at all; they exist because the RegistrationType axes demand them.
- **Tax handling of any kind.** LanCore stores a single integer price in minor
  units with no tax rate, no net/gross distinction and no VAT ID anywhere. Every
  tax capability in Konventix is new construction — see **D-COM-01**.

## Open decisions

### D-COM-01: Does the price live on the RegistrationType or in a Commerce catalogue?

**Context:** LanCore puts an integer `price` (minor units, no tax rate, no
net/gross flag) directly on `TicketType` and `Addon`, and `InteractsWithShop`
reads it straight off the model. ADR-0006 says the net-vs-gross *display*
decision hangs on the RegistrationType, not globally, and both SPECs flag this
as unresolved. The constraint that makes it hard: Registration must declare
what a registration costs (it owns the offer), but Commerce must own pricing
and tax calculation (it owns money) — and ADR-0004 forbids either module from
reading the other's models. The answer also decides where German VAT lands:
standard vs. reduced rate, reverse charge for B2B exhibitors, and the small
business exemption under §19 UStG.

**Option A — Registration declares, Commerce calculates.** `RegistrationType`
carries a price declaration value object (net amount, tax rate, net-vs-gross
display flag). It travels in the `RegistrationSettlementRequested` payload;
Commerce computes line totals, tax and rounding, and snapshots the result onto
the order.
*Pros:* one place to configure an offer; the organizer sets a price where they
set everything else about the type; matches ADR-0006's wording literally;
zero-price free types need no Commerce record at all.
*Cons:* tax rules end up split — the *rate* is chosen in Registration, the
*treatment* (reverse charge, exemption) applied in Commerce, and getting reverse
charge right requires the billing party's VAT ID, which Registration does not
have when the type is configured. Repricing history lives nowhere.

**Option B — Commerce owns a product catalogue; RegistrationType points at it.**
Commerce gets `Product` / `PriceComponent` entities with validity windows and
tax treatment. `RegistrationType` references a product by ULID.
*Pros:* all pricing and tax knowledge in one module, including price history,
tiered/early-bird pricing and per-audience tax treatment; add-ons, sponsorship
packages and merchandise reuse it without further modelling; reverse charge is
decided where the VAT ID is known.
*Cons:* configuring a registration type becomes a two-object job across two
admin screens, which is a real UX cost for the common "one €30 visitor ticket"
case; Commerce is no longer dormant for brands that only sell simple tickets;
more up-front modelling before the first paid flow ships.

**Option C — Registration owns price, Commerce owns tax.** Split by concern
rather than by object: the RegistrationType carries the amount and the display
flag; Commerce owns a tax-treatment engine keyed by (brand, billing party,
product tax class).
*Pros:* keeps offer configuration in one place while putting the genuinely hard
part where the data is; the tax class on the type is a small, stable field.
*Cons:* "who owns pricing" now has the answer "both", which is the kind of
answer that erodes; early-bird and tiered pricing still have no home.

**Recommendation:** Option A for release, with the price declaration modelled
as a value object from the first line of code — the migration path to a
catalogue is then a projection rather than a rewrite, and Phase 1 (free
registrations only) needs no Commerce entity at all.

### D-COM-02: Are invitation codes vouchers, or a separate concept?

**Context:** LanCore has exactly one code concept: `Voucher`, which changes a
price (fixed or percentage), is optionally event-scoped, and has a use counter.
ADR-0005's worked exhibitor example hands out "ten visitor admissions as
voucher codes" — but those codes grant *access to an offer*, not a discount.
Registration's SPEC calls these invitation tokens and lists the overlap as an
open question; Commerce's SPEC claims vouchers. Both are right about their half.

**Option A — Two separate concepts.** Registration owns `Invitation` (grants
access to an `invite_only` or `link` type, single-use or N-use, optionally
pre-bound to an email); Commerce owns `Voucher` (changes a price, redeemed
against an order). A code may be one or the other, never both.
*Pros:* module boundaries stay clean and match the ADRs; each has a genuinely
different lifecycle (an invitation is consumed at registration, a voucher at
settlement); an invitation to a `free` type never touches Commerce.
*Cons:* "here is a code for a free exhibitor pass" is arguably one user-facing
concept split into two admin screens; a 100%-discount voucher and an invitation
to a free type are indistinguishable to the recipient, and organizers will ask
why there are two.

**Option B — One `Code` entity with effects.** A single Registration-owned code
carrying a list of effects: unlock a type, and/or apply a discount. The discount
effect is passed to Commerce in the settlement request.
*Pros:* one admin screen, one code table, one redemption log; matches how
organizers actually think ("a code that gets you in cheaper").
*Cons:* Registration ends up owning discount rules, which is Commerce's job by
ADR-0006; discount validation (currency, rounding, stacking, minimum order
value) leaks into a module with no money model; the redemption record has to
live in two places or be split.

**Option C — Vouchers only, with an "unlock" voucher type.** Extend Commerce's
`VoucherType` with a third case that grants access rather than discount, and
have Registration query Commerce for validity.
*Pros:* smallest delta from LanCore; one code table.
*Cons:* directly violates ADR-0004 — Registration would have to consult
Commerce synchronously to decide whether a form may be submitted, and a free
invite-only registration would depend on the money module being reachable. This
option is listed for completeness; it is the one that ages worst.

**Recommendation:** Option A. The lifecycle difference is real — an invitation
is consumed when the contract is created, a voucher when it is settled — and the
"two screens" objection is solvable in the UI without merging the models.

### D-COM-03: What is a quota attached to?

**Context:** LanCore's quota is an integer column on `TicketType`, and remaining
availability is `quota - tickets()->count()` evaluated on every catalogue render
— a live COUNT with no reservation and no protection against two concurrent
checkouts overselling the last unit. Separately, `CreateOrder` checks venue seat
capacity via `Event::remainingSeatCapacity()`, so there are already two
independent capacity systems that do not know about each other. ADR-0005 makes
`Quota` a standalone entity but does not say what it is attached to.

**Option A — Named quota pools, referenced by many types.** A `Quota` belongs to
an event, has a total, and any number of RegistrationTypes consume from it. A
type may consume from several pools; a registration consumes one unit of each
pool its type references.
*Pros:* expresses the cases that actually occur — "600 people in the hall total,
of which at most 80 exhibitor crew"; one pool can back early-bird, regular and
late types of the same product; venue capacity becomes just another pool rather
than a special code path.
*Cons:* multi-pool consumption needs an all-or-nothing reservation across pools,
which is the hard part (partial exhaustion must roll back cleanly); the admin UI
has to explain a many-to-many relationship.

**Option B — One quota per registration type.** Keep LanCore's shape but as an
entity with reservation semantics: a row per type, explicit `reserved` and
`consumed` counters, released on cancellation.
*Pros:* far simpler to implement, explain and test; covers the single-event
single-ticket case that is the overwhelming majority; concurrency is one row
lock.
*Cons:* cannot express a shared ceiling, so "total venue capacity" stays a
second, parallel mechanism — exactly the situation LanCore is in; early-bird
and regular pricing of the same product need two quotas that must be kept in
sync by hand.

**Option C — Pools plus a per-type cap.** Pools as in Option A, with an optional
per-type maximum layered on top.
*Pros:* covers both shapes; the per-type cap is a cheap addition once pools
exist and removes the main argument for Option B.
*Cons:* two limits to reason about when debugging "why can nobody register";
the error message has to say which limit bit.

**Recommendation:** Option C, built as Option A first with the per-type cap
added once a real event needs it — the pool model is the one that cannot be
retrofitted later without a data migration, and it absorbs venue capacity
instead of leaving it as a second system.

### D-COM-04: Who owns refund and cancellation, and what does a refund mean for access?

**Context:** LanCore has no refund implementation whatsoever — `OrderStatus`
has a `Refunded` case that nothing ever sets, and the SRS names a `cancelled`
status that does not exist in the enum. So this is design, not porting. Three
distinct acts are involved and LanCore has one word for all of them: the
*registration* is withdrawn (contract), the *order* is cancelled or credited
(money), the *admissions* are revoked (access). ADR-0006 puts refunds and
credit notes in Commerce as domain flows with PSP calls as side effects, and
forbids Commerce from touching Registration models. Commerce's SPEC explicitly
asks whether Commerce may hold any opinion about a refund when the admissions
have already been checked in.

**Option A — Registration is the entry point; Commerce follows.** Cancelling a
registration is the only user-facing act. Registration emits
`RegistrationCancelled`; Commerce reacts by cancelling the order or issuing a
credit note plus a PSP refund; Admission reacts by revoking admissions.
*Pros:* one act, one audit story; matches the direction of the confirmation
flow so there is one causal direction in the system; the refund policy (who
gets money back, how much, until when) sits next to the cancellation policy.
*Cons:* a pure money correction — wrong amount invoiced, duplicate payment,
goodwill partial refund — has no path, because it would require cancelling a
registration that should stay valid. Organizers will do it manually and Commerce
will drift from reality.

**Option B — Two independent entry points.** Registration owns withdrawal;
Commerce owns refunds and credit notes as first-class operations that can be
performed alone. Each emits, each listens, and a full cancellation is the
common case where both happen.
*Pros:* both real workflows are expressible; matches ADR-0006's framing of
refunds as domain flows in Commerce; a goodwill refund does not invalidate
anyone's badge.
*Cons:* the two can disagree — a fully refunded registration that is still
confirmed is now representable and someone will create one; needs an explicit
rule for what a Commerce-initiated full refund implies, which is precisely the
implication ADR-0005 was written to remove.

**Option C — Registration entry point, plus an explicit "money-only correction"
operation in Commerce.** Option A, plus a deliberately narrow, separately
authorized Commerce operation for corrections that names itself as not
affecting entitlements.
*Pros:* one default path; the exception exists but is visibly an exception and
is capability-gated separately; the audit trail distinguishes "cancelled" from
"corrected".
*Cons:* a third concept to document; the boundary between "correction" and
"refund" will be argued about in code review.

**Recommendation:** Option C. Option A's missing money-correction path is a
guaranteed source of manual workarounds, and Option B's symmetric entry points
reintroduce the "payment implies status" ambiguity from the other direction.

For "already checked in": Commerce should report the refund and nothing more.
Whether a checked-in admission survives a refund is Registration's call, driven
by the type's policy — the door must never learn about it (ADR-0005).

### D-COM-05: How are invoice numbers allocated?

**Context:** `GenerateInvoiceNumber` reads `shop_settings.invoice_counter_<year>`,
adds one and writes it back — instance-wide, unlocked, and racy under
concurrency. ADR-0006 mandates per-brand invoice number ranges; ADR-0001 says
brands are public identities under one operator, and initially exactly one
operator exists. German bookkeeping (GoBD, §14 UStG) requires numbers to be
unique and gapless within the issuing entity, and it is the *operator* — the
legal entity — that issues, not the brand. So the ADR and the tax law point in
slightly different directions, which is what makes this open.

**Option A — Per-brand sequence.** Each brand gets its own range and prefix
(`SXL-2026-00001`, `XPT-2026-00001`).
*Pros:* exactly what ADR-0006 says; invoices carry the brand identity the
customer recognizes; a brand can be handed over or split off without renumbering
history.
*Cons:* if all brands belong to one legal entity, the tax advisor receives
several parallel sequences for one taxpayer. That is defensible under German
practice when the ranges are disjoint and documented, but it is a conversation
to have with an accountant rather than a decision to make in code alone.

**Option B — Per-operator sequence, brand shown only as a prefix label.** One
gapless sequence per legal entity; the brand appears on the document but not in
the number.
*Pros:* unambiguously matches how the tax authority views the taxpayer; one
sequence to prove gapless; simplest bookkeeping export.
*Cons:* contradicts ADR-0006 as written; if a brand is ever spun out, its
invoice history is interleaved with another brand's; the number reveals total
cross-brand invoice volume to every customer.

**Option C — Per-brand sequence with an operator-scoped uniqueness guarantee.**
Numbers are drawn per brand, but the allocation table is operator-scoped and
every issued number is recorded in one operator-level ledger for the export.
*Pros:* satisfies ADR-0006 and gives the tax advisor a single gapless view;
brand spin-out stays clean.
*Cons:* two structures where one might do; the ledger must be written in the
same transaction as the allocation or the guarantee is worthless.

**Recommendation:** Option A, implemented as a dedicated `InvoiceNumberRange`
row per brand and year with the number drawn under a row lock inside the
invoice-issuing transaction (never a read-modify-write on a settings blob), and
the bookkeeping export able to emit all brands of an operator as one file. That
is Option C's benefit at Option A's cost. Confirm the multiple-sequence question
with the tax advisor before the first real invoice — it is cheap now and
expensive after.

### D-COM-06: What does the entrance client trust when it is offline?

**Context:** Despite the module map's promise, LanEntrance is **not** offline
capable today: OCD §5.2 describes a fast local signature pre-check against the
cached JWKS followed by an unconditional call to the authoritative
`POST /api/entrance/validate`. If the uplink dies, the door stops. The signed
token (`TKT-F-017/018`) proves *authenticity* but says nothing about
*revocation* or *already checked in*, because both live in the database.
Admission's SPEC names this as the decision that determines token format,
revocation latency and how much data sits on a device — and ADR-0002 names the
door as the one legitimate case for runtime separation.

**Option A — Signature-only trust.** The device verifies the KVX1 signature and
expiry against the cached JWKS and admits on that alone, buffering scans for
later reconciliation. No admission list on the device.
*Pros:* zero PII on the device — a stolen scanner leaks nothing and can forge
nothing (public keys only); works with any number of admissions; the device
needs no sync window before doors open; token format needs no change.
*Cons:* a revoked admission is admitted until its token expires; duplicate
detection is per-device only, so the same QR photographed and shared works at
two lanes simultaneously; the operator sees a name only if it is in the token,
which would mean putting PII in the QR.

**Option B — Manifest download.** The device downloads a manifest of valid
admissions before the event (holder hash or name, admission ID, validity
window, revocation flag) and checks membership locally, refreshing deltas
whenever connectivity returns.
*Pros:* revocation works within the refresh interval; the operator sees the
holder's name and can search offline; cross-device duplicate detection is
possible within a synced window; the door genuinely keeps working.
*Cons:* attendee data at rest on a device that lives on a folding table at a
venue — a GDPR question Admission's SPEC already flags and that needs
Compliance's sign-off; a large event means a real sync step and a stale-manifest
failure mode; the manifest needs encryption at rest, remote wipe and an
expiry-after-event rule.

**Option C — Signature-only by default, with a revocation-delta feed.** Option
A plus a small, frequently refreshed list of *revoked* admission IDs and
already-checked-in IDs. Positive decisions come from the signature; negative
decisions come from the delta.
*Pros:* the list is short (revocations are rare) and contains no PII — just
opaque IDs; closes Option A's worst gap; degrades to Option A rather than to
"door closed" when the feed is stale.
*Cons:* the operator still cannot see who is in front of them, so the manual
name-based fallback that LanCore's search endpoint provides is gone offline;
cross-device duplicate detection is still limited by the refresh interval; two
sync mechanisms if a manifest is added later anyway.

**Recommendation:** Option C. It is the only one that is both genuinely offline
and free of attendee PII on venue hardware, and it degrades in the right
direction. Treat holder-name display as a separate, opt-in feature that carries
its own Compliance decision, rather than smuggling it in as a side effect of the
trust model.

### D-COM-07: What is a cart when registration is not always a purchase?

**Context:** LanCore's cart is a purchase basket: one per user per event,
polymorphic items, cleared on checkout, `event_id` nulled when empty. Under
ADR-0005 a registration may be free (crew, speaker), invoice-settled
(exhibitor), approval-gated, or instantly paid — and only the last of those
looks anything like a shopping cart. Commerce's SPEC claims `Cart` outright but
also asks whether `settlement: free` even produces an order. If the cart stays a
Commerce object, a free crew signup either routes around it or drags a money
module into a flow with no money in it.

**Option A — Cart stays a Commerce purchase basket.** Only `settlement: instant`
registrations go through it. Free, invoice and external settlements submit a
registration form directly and never touch Commerce until (and unless) a
settlement request is emitted.
*Pros:* the cart keeps its familiar meaning and its familiar UI; Commerce is
genuinely dormant for free-only brands, which is exactly the Phase 1 scope;
smallest delta from LanCore.
*Cons:* two visibly different flows for what an organizer configures on one
screen — "why does the visitor ticket have a basket and the crew signup not?";
a mixed cart (paid visitor ticket + free crew signup for the same person) has no
representation; the "continue shopping / review / submit" UX has to be built
twice.

**Option B — Registration owns a "registration basket"; Commerce owns
settlement only.** A pre-submission draft holding intended registrations
regardless of settlement mode. On submit it produces registrations; only those
whose settlement needs money produce a `RegistrationSettlementRequested`, and
Commerce's `Order` is the first Commerce object that exists.
*Pros:* one flow for all four settlement modes; mixed baskets work; Commerce
stays money-only, which is what ADR-0006 actually asks for; free-only brands
never construct a Commerce object.
*Cons:* Registration grows a stateful draft object with quantities and
prerequisite validation, which looks a lot like a cart and will be called one;
price display in the basket needs Commerce's numbers before an order exists,
which either duplicates calculation or requires a read-only pricing query across
the boundary; discount preview before submission gets awkward.

**Option C — No basket at all: one registration per submission.** Each
registration is submitted on its own; multiple registrations become multiple
acts, optionally settled together on one invoice.
*Pros:* the simplest possible model and the one that matches exhibitor and
speaker reality best; no draft state to expire, garbage-collect or migrate.
*Cons:* actively bad for the highest-volume case — a person buying four visitor
tickets and two parking add-ons will not submit six forms; grouping separate
registrations onto one payment needs its own concept, which is a cart wearing a
hat.

**Recommendation:** Option B, named something other than "cart" (`RegistrationDraft`)
so nobody assumes money. It is the only option where the four settlement modes
share one user-facing flow, and the pricing-preview cost is real but bounded — a
read-only price quote is a legitimate Commerce query that returns scalars.

### D-COM-08: Does the door collect payment?

**Context:** LanCore's entrance API returns `payment_required` with an amount
and line items when a ticket's order is on-site and unpaid, and
`POST /api/entrance/confirm-payment` books the payment, dispatches a receipt and
checks the holder in — in one call from a device at the door. ADR-0005 states
flatly that check-in asks "is this admission valid?", never "is it paid?", and
Admission's SPEC repeats it. But cash at the door is a real, common LAN and
small-event workflow that our own events need, and ADR-0007 says the platform
never holds funds — which a cash payment collected by an organizer's staffer
does not violate, since the money never touches the platform.

**Option A — Drop it. The door never sees payment.** On-site settlement is
booked in Commerce's back office before or after entry; unpaid registrations
simply are not confirmed and therefore have no admission to scan.
*Pros:* ADR-0005 stays absolutely clean and the entrance API keeps a single
responsibility; the entrance client needs no money model, no amount comparison
and no receipt logic; the offline client stays trivially safe, because deciding
payment offline is a genuinely hard problem.
*Cons:* removes a workflow our own events use today; the practical result is a
second queue and a laptop at the door running the admin UI, which is worse for
the organizer than what LanCore already has.

**Option B — The door is a Commerce point of sale that happens to be at the
door.** The entrance client gets a separate, separately authorized POS screen
calling Commerce endpoints to book a payment. Booking a payment emits
`PaymentReceived` / `OrderPaid`; Registration confirms if its
`confirmation_gate` says so; Admission issues an admission; the operator scans
it. Two operations, correct causal order, no shortcut.
*Pros:* keeps the real workflow while every module keeps its own job; the
validate endpoint stays free of payment logic; reuses manual payment booking,
which ADR-0006 already makes first-class; the capability split (collect money vs.
scan) is enforceable and matches Admission's "door staff get scan and print only".
*Cons:* two round trips and an event-driven hop between them, so the operator
may briefly see "not yet valid" after collecting cash — a UX problem that needs
deliberate handling; requires connectivity, so it cannot work in the offline
mode of **D-COM-06**; the entrance client is no longer purely an Admission
client.

**Option C — Keep a combined endpoint, but in Commerce.** A single
"collect and admit" operation that books the payment and, once the resulting
confirmation has produced an admission, checks it in — orchestrated server-side
so the operator sees one action.
*Pros:* best operator experience, closest to LanCore's behaviour; the
orchestration is explicit and in one place rather than implied by a status
check at the gate.
*Cons:* recreates the payment→access coupling as an API convenience, and
conveniences become assumptions; the endpoint has to wait for an asynchronous
confirmation to complete, which means either synchronous cross-module calls
(forbidden) or a polling/latency story at the busiest moment of the event.

**Recommendation:** Option B, with the latency handled honestly in the client
(collect payment → the screen shows "confirming…" → admission appears → scan).
The workflow is worth keeping; the shortcut is not. This is the single place
where the port most tempts a re-fusion of payment and access, so it is worth
paying a UX cost to keep the boundary.

### D-COM-09: Who holds an admission that has no person yet?

**Context:** In LanCore a group ticket exists with `max_users_per_ticket` seats
and the manager attaches people afterwards, so "an entry that nobody is named
for" is representable only as a pivot row that has not been created yet. Both
the Registration and Admission SPECs list this as an open question, and B2B is
where it bites: an exhibitor books ten visitor passes in March and names the
people in August, or never — they hand out codes at their booth.

**Option A — Admission issues unassigned admissions immediately.** On
`RegistrationConfirmed`, Admission creates N admissions with a null holder,
each claimable later via a code or by the registration's responsible party.
*Pros:* the entitlement is materialized once, at one moment, by one rule — no
second "now materialize the rest" trigger; each pass has its own identity,
token and audit trail from day one, so handing out ten codes at a booth is the
native flow; Registration never learns about people.
*Cons:* ten admission rows with no holder are attendance records about nobody,
which needs a GDPR answer (they are fine — there is no data subject until
claimed — but it must be stated); revoking "three of the ten unclaimed" needs a
selection rule; the admission list shows placeholder rows that organizers may
find noisy.

**Option B — Registration holds unfilled grant slots; Admission issues only on
naming.** The confirmed registration carries "10 visitor grants, 3 filled";
Admission creates an admission when a person is named.
*Pros:* an Admission always has a holder, which keeps that module's invariants
tight and its GDPR story trivial; the "how many are still open" question is
answered where the contract lives, which is also where the organizer asks it.
*Cons:* two materialization moments instead of one, and the second one is a
Registration→Admission command rather than an event, which strains ADR-0004;
codes handed out at a booth have nothing to be codes *for* until redeemed, so
Registration grows a second token concept on top of invitations (**D-COM-02**);
quota accounting has to count unfilled slots, so Registration duplicates part of
Admission's counting.

**Option C — Unassigned admissions exist, but as a distinct `AdmissionClaim`
entity.** Admission owns both: a claim (transferable, no holder, has a code) and
an admission (has a holder, has a token). Redeeming a claim produces an
admission.
*Pros:* the two states are genuinely different things and modelling them
separately keeps `Admission` clean; transfer and resale hang naturally off the
claim; one module still owns the whole lifecycle.
*Cons:* a third entity in the highest-traffic part of the model; "how many
people can enter" now sums two tables; most events never issue a single claim.

**Recommendation:** Option A. The `Admission` lifecycle already has an
`AdmissionHolderAssigned` event in the SPEC, which means the nullable-holder
state is designed in; adding a second entity or a second materialization moment
buys precision that only B2B events need, and Option A's GDPR objection resolves
to a one-paragraph statement rather than a design change.

### D-COM-10: Where do add-ons live?

**Context:** `Addon` is a full second purchasable in LanCore: event-scoped,
priced, quota'd, consuming seats, optionally requiring a ticket, attached to
tickets through a pivot that snapshots the price paid. Real add-ons in our
events are mixed in kind — a parking pass (a resource), a T-shirt (merchandise),
breakfast (a service), an extra seat (a resource). LanCore models all four
identically because it only had one hammer. The module map is silent on add-ons
entirely, which is why this needs an explicit answer rather than an assumption.

**Option A — Add-ons are RegistrationTypes with a prerequisite.** An add-on is
just a registration type whose `grants` are non-admission (or empty) and which
declares a prerequisite on another type.
*Pros:* one offer model, one admin screen, one quota mechanism, one settlement
path; the prerequisite concept already exists as LanCore's
`PurchasableDependency`; a "parking" registration type that grants a parking
resource claim is exactly what the axes were designed for.
*Cons:* a T-shirt is not a registration by any reading, and calling it one will
confuse every organizer who sees it in the registration list; reporting
("how many registrations?") needs a filter to avoid counting T-shirts as
attendees.

**Option B — Commerce owns a product catalogue; add-ons are products.** Pairs
naturally with Option B of **D-COM-01**. Add-ons are Commerce line items with
no registration semantics at all.
*Pros:* merchandise, sponsorship packages and add-ons share one model; keeps
non-attendance products out of the registration count entirely; add-ons acquire
price history and tiering for free.
*Cons:* an add-on that grants something — a parking space, an extra seat, a
locker — now needs Commerce to trigger an entitlement, which means Commerce
knowing about grants; that is the coupling ADR-0006 forbids; free add-ons on a
free registration would require a Commerce record for something with no money.

**Option C — Split by what the add-on grants.** Things that grant an
entitlement (parking, extra seat, locker, meal) are registration types with
prerequisites (Option A); pure goods with no entitlement (merchandise) are
Commerce products (Option B) and are deferred past release.
*Pros:* each thing is modelled as what it is; the release scope shrinks to the
entitlement-granting case, which is the one our own events need; merchandise can
arrive later without disturbing anything.
*Cons:* organizers must understand which bucket a new add-on falls into, and
the boundary case ("breakfast" — is that an entitlement?) will be argued about;
two admin surfaces eventually.

**Recommendation:** Option C, shipping only the Option A half at release. Every
add-on our events currently sell grants something, so the merchandise half can
wait until there is a real merchandise requirement to shape it.

### D-COM-11: Who owns the terms a registrant must accept?

**Context:** LanCore has three overlapping mechanisms: `GlobalPurchaseCondition`
(terms and privacy, shown at every checkout, also printed onto panel 3 of the
ticket PDF), `PaymentProviderCondition` (the same shape, filtered by payment
method), and `PurchaseRequirement` (per-ticket-type rules with a `requires_scroll`
flag and a JSON array of acknowledgement checkboxes). Acceptance is recorded in
`CheckoutAcknowledgement`. Meanwhile the Policy domain owns actual consent, and
the SRS explicitly states that `SHP-F-010`/`SHP-F-011` are "explicitly distinct
from the Policy domain" — two consent systems, deliberately. In Konventix,
Compliance owns consent and policies, and a `settlement: free` registration
never reaches a checkout at all, so terms attached to checkout would simply never
be shown to crew or speakers.

**Option A — Compliance owns all of it.** One `Policy` concept with versions and
one `ConsentRecord`. Registration declares which policies a type requires;
Commerce declares which a settlement method requires; both are presented at the
appropriate step but stored once.
*Pros:* one consent record per person per policy version, which is what GDPR
Art. 7(1) actually wants to see; consent withdrawal (`SET-F-011`) works
uniformly; free registrations get their terms; the ticket PDF has one source to
render.
*Cons:* Compliance becomes a dependency of every registration and checkout flow;
per-type bespoke house rules ("I confirm I will not bring my own router") are
not really policies and inflate the policy catalogue; three mechanisms collapse
into one, which is a bigger migration than it looks.

**Option B — Split by nature: policies in Compliance, house rules in
Registration.** Compliance owns legally-significant documents (terms, privacy,
withdrawal). Registration owns per-type acknowledgements as part of the
registration form. Commerce owns settlement-method terms.
*Pros:* each module owns what it can actually explain; per-type rules stay next
to the type where organizers edit them; the form-answers mechanism Registration
needs anyway absorbs the house rules at no extra cost.
*Cons:* three storage locations again, so "what did this person agree to" is a
three-way join; the boundary between a legally-significant policy and a house
rule is exactly the judgement call an organizer will get wrong; GDPR export has
to gather from three modules.

**Option C — Compliance owns the records; the modules own the presentation.**
Registration and Commerce declare requirement sets and render them in their own
flows, but every acceptance — policy or house rule — is written to Compliance's
consent store with a source reference.
*Pros:* one place to answer "what did this person accept and when", which is the
question that matters in a dispute; each module keeps its own editing UI and its
own vocabulary; withdrawal and export have one home.
*Cons:* Compliance stores records for things that are not policies, so its model
needs a looser shape than a versioned policy document; two concepts
(requirement vs. policy) still exist, just with one storage layer.

**Recommendation:** Option C. The requirement that actually has legal weight is
being able to show what a person accepted and when; the requirement that has
usability weight is editing house rules next to the type. Option C satisfies
both, and it is the only one where a free crew registration and a paid visitor
checkout produce the same kind of evidence.

### D-COM-12: Does a free registration produce a zero-value order?

**Context:** In LanCore every ticket comes from an order, because tickets are
only ever created inside `FulfillOrder` — there is no path to a ticket that does
not pass through the shop. Under ADR-0005 a `settlement: free` crew registration
involves no money at all, and Commerce's SPEC states that such a brand "never
causes an order to exist" while simultaneously listing this as an open question.
Phase 1 of the PoC is free registrations only, so this decides whether Commerce
ships at all in Phase 1.

**Option A — No Commerce record for free settlements.** Registration confirms,
Admission issues, Commerce is never involved.
*Pros:* Commerce stays genuinely dormant, which is what its SPEC promises and
what makes Phase 1 small; no zero-value invoices to explain to a tax advisor;
no meaningless rows in the order list.
*Cons:* "show me everything this person has with us" and "show me all
registrations for this event with their financial status" have to union two
sources; reporting code branches on settlement mode forever; if a free type
later becomes paid, existing registrations have no order to amend.

**Option B — Every registration produces an order, zero-total when free.**
*Pros:* one uniform path — every registration has exactly one order, so every
report, export and admin screen has one join; a free-to-paid type change is an
order amendment rather than a special case; the settlement-request event fires
unconditionally, so there is one code path to test.
*Cons:* Commerce is no longer dormant, so Phase 1 grows a module it does not
need; zero-value orders and any documents they imply need a suppression rule
(nobody wants a €0.00 invoice PDF); it contradicts Commerce's SPEC as written.

**Option C — No order, but Registration exposes a uniform settlement summary.**
Free registrations have no Commerce record; Registration carries a small
projected settlement summary (mode, status, amount, order reference or null)
maintained from Commerce events, and every report reads that.
*Pros:* Commerce stays dormant and reporting stays uniform; the projection is
a read model of exactly the kind ADR-0004 prescribes; the "financial status"
column exists for all four settlement modes.
*Cons:* a projection that can drift, needing a rebuild path; two places display
a payment status, so they must agree; a small amount of money vocabulary lands
in Registration.

**Recommendation:** Option C. It preserves the dormancy that makes Phase 1
tractable while removing Option A's real cost, and the read model it needs is
one Registration will end up building anyway to render a registration list.
