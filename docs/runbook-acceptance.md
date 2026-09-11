# Acceptance runbook

What a person walks through to satisfy themselves that Konventix works, in an
order that builds on itself: bring an instance up, sell something, let someone
in, then check the pieces that only a human can judge.

Each step says what to do and **what you should see**. A step that does not
match is a finding, not a mistake on your part — note the step number.

Written 2026-09-11 against `develop` after the seating and ticket merge.
Sections marked **HUMAN ONLY** are the ones no test covers and that the
milestone sweep left unticked on purpose.

---

## 0. Bring it up

```bash
cd ~/git/konventix/konventix-poc
docker context use default            # Docker Desktop steals the context
vendor/bin/sail up -d
vendor/bin/sail artisan migrate --force
vendor/bin/sail npm run build
vendor/bin/sail artisan octane:reload
docker compose exec -d -u sail laravel.test php artisan inertia:start-ssr
```

Then seed and fill the read models:

```bash
vendor/bin/sail artisan konventix:seed --default
vendor/bin/sail artisan identity:replay-profiles
vendor/bin/sail artisan event:replay-details
vendor/bin/sail artisan registration:announce-ownership
```

The seeder makes every hall plan an empty draft, so import the committed
example and publish it, or there is nothing to click in section 3:

```bash
vendor/bin/sail artisan resources:plan-import \
  app-modules/resources/database/seeders/plans/example-160.json \
  --event=sxlan-2026 --category=sxlan-2026-seat --name="Halle A" --publish
```

**You should see** "Imported plan … 160 place(s), published."

### Who you are

| Role | Where | Sign in as |
| --- | --- | --- |
| Operator | `http://admin.localhost:18080` | `admin@konventix.test` / `password` |
| Participant | `http://sxlan.localhost:18080` | any seeded address / `password` |

The `dev` brand has the Resources module switched **off**. Use `sxlan`,
`xpertise` or `nordcon`, never plain `localhost`, or seating will be missing
and you will think it is broken.

Everything off production wears a corner ribbon and the ticket carries a
TESTSYSTEM band. If you do **not** see the ribbon, stop: the instance thinks
it is production and the mail guard is off.

---

## 1. The operator console

1. Sign in on the admin host. **You should see** the dashboard and the
   environment ribbon.
2. Open Registrations, Registration types, Quotas. **You should see** seeded
   rows for each brand.
3. Open a registration type and look at its grants card. **You should see**
   the seating grant with a category select.

---

## 2. Selling something

4. As a participant, open the shop for SXLan 2026 and buy a ticket type that
   grants seating. Pay with a Stripe test card.
5. **You should see** the order confirmed, and in your mail the confirmation
   with **an `.ics` file attached**. Open it. **You should see** the event at
   the right local time in your calendar app.
6. Open the invoice from the order. **You should see** a PDF whose totals
   match the order.

**Known gap, do not raise it:** an order over 250 euro does not yet ask for a
postal address (#4).

---

## 3. Seating

7. Operator: Seating plans → Halle A → Edit. **You should see** 160 places.
8. Block one place, save. **You should see** the place turn blocked, and the
   linked quota's consumed count rise by one.
9. Unblock it. **You should see** the count fall again, and if anyone was on
   the waitlist, an offer go out.
10. Reserve a place for the organisation, then release it. **You should see**
    the same movement as blocking and unblocking.

### HUMAN ONLY — the picker (#276)

11. On a **phone**, or a browser at 400 px wide, open
    `/my/registrations/<id>/seat` as a participant who holds an unplaced claim.
12. **Using only the keyboard**, Tab to a seat in the list view and take it
    with Enter or Space. **You should see** the seat become yours without a
    mouse ever being used.
13. Pinch to zoom and drag to pan the plan. **You should see** it respond, and
    every tappable target should be comfortably hittable with a thumb.
14. Look at free, mine, held, booked and unavailable seats. **You should see**
    them distinguishable **by symbol and text, not by colour alone**. Squint,
    or turn the screen greyscale.

### HUMAN ONLY — the plan editor (#278)

15. Operator: open a plan for editing. Draw a zone. Add a group with a 2×8
    grid generator and a label schema. Save.
16. **You should see** 16 places with references A-01 to A-08 and B-01 to B-08.
17. Regenerate the same group as 2×10 and save. **You should see** four places
    appended and the existing sixteen keep their identities — nothing
    renumbered, nothing orphaned.

### HUMAN ONLY — the console in English (#275)

18. Walk Categories, Seating plans and the type editor. **You should see**
    English throughout, with no stray German label.
19. Switch the Resources module off for the brand, reload. **You should see**
    its navigation entries gone. Switch it back on and see them return.

---

## 4. Tickets

20. As the buyer, open your registration. **You should see** a ticket card
    naming how many tickets the file holds.
21. Download it. **You should see** one A4 page per admission, each with a QR,
    the holder's name, the role, the days and a door code.
22. Check the event line. **You should see** the event's **local wall-clock
    time**, matching what the public event page says. These disagreed until
    today; if they disagree again, that is a regression.
23. **You should see** a faint brand mark, the diagonal forensic repeat naming
    the holder and the event, and a TESTSYSTEM band — and the **QR must be
    completely clear of all three**.

### HUMAN ONLY — a real scan (#72)

24. Print page one, or display it on another screen, and scan the QR with the
    entrance scanner. **You should see** it read first time. This is the check
    that no automated test can stand in for.
25. Open `/my/tickets` as someone who has claimed an admission. **You should
    see** only their own, and an unclaimed admission must appear for nobody.
26. On the public event page, press the calendar button. **You should see** a
    `.ics` download that opens correctly.

---

## 5. The door

27. Scan a valid ticket. **You should see** it accepted and a check-in recorded.
28. Scan the same one again. **You should see** the re-entry policy decide,
    not a crash.
29. Rotate an admission's token, then scan the **old** printed ticket.
    **You should see** it refused as superseded. Before today it would have
    been accepted — this is the fix worth confirming.
30. Take the scanner offline, scan an admission it has never seen.
    **You should see** it accepted. Failing open offline is deliberate.

---

## 6. Money

31. Refund an order from the console. **You should see** the registration
    cancelled, the quota returned and the waitlist move.
32. Cancel a registration. **You should see** its seat released and offered on.

**Known gaps, do not raise them:** a whole-order refund does not yet cancel
every registration line (#187), and a removed cart line cancels without asking
(#219).

---

## 7. When something looks wrong

- **A page renders differently than a passing test suggests** — the worker is
  stale. `vendor/bin/sail artisan octane:reload`.
- **Settings pages look unstyled or fail** — the render process died.
  `docker compose exec -d -u sail laravel.test php artisan inertia:start-ssr`.
- **Seating is missing entirely** — you are on the `dev` brand, which has the
  module switched off.
- **A ticket shows no seat** — that is correct and deliberate. A seat claim
  belongs to a registration and a ticket page to a person, and which place is
  whose is not modelled.
- **The stack says it is not running** — Docker Desktop stole the context.
  `docker context use default`.

Every finding belongs on its issue with the step number from this document, so
the next person can reproduce it in one move.
