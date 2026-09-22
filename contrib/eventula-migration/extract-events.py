#!/usr/bin/env python3
"""Zieht die zu migrierenden Events samt Ticketarten als NDJSON.

Je Event eine Zeile, die Ticketarten haengen darin. Test- und Draft-Events
kommen nicht mit; welche das sind, steht in SKIP und ist eine Entscheidung,
keine Heuristik.

    python3 extract-events.py > work/events.ndjson
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

CONTAINER = os.environ.get("EVENTULA_CONTAINER", "eventula-mig")
PASSWORD_FILE = os.environ["EVENTULA_MYSQL_PW_FILE"]

# Die sechs Events, die migriert werden. Nicht dabei: 1 (INTERNAL TESTING),
# 5 (Attendee-Muelltonne) und 8 (SX29 Testlan) — Werkzeuge des Altsystems,
# keine Veranstaltungen.
EVENTS = [2, 3, 4, 6, 7, 9]

# SX31 ist das laufende Event. Es kommt denselben Weg wie die Historie und
# wird danach von Hand richtiggestellt (Runbook, Schritt 2.4).
LIVE_EVENT = 9

EVENT_QUERY = f"""
select id, display_name, slug, start, end, capacity, coalesce(desc_short,'')
  from events where id in ({','.join(map(str, EVENTS))}) order by id
"""

TYPE_QUERY = f"""
select tt.id, tt.event_id, tt.name, format(tt.price, 2), tt.quantity,
       tt.seatable, coalesce(tt.sale_start,''), coalesce(tt.sale_end,''),
       coalesce(tt.no_tickets_per_user,0),
       (select count(*) from tickets t where t.ticket_type_id = tt.id)
  from ticket_types tt
 where tt.event_id in ({','.join(map(str, EVENTS))})
 order by tt.event_id, tt.id
"""


def mysql(sql: str) -> list[list[str]]:
    password = Path(PASSWORD_FILE).read_text().strip()
    out = subprocess.run(
        ["docker", "exec", "-i", CONTAINER, "mysql",
         "-uroot", f"-p{password}", "--default-character-set=utf8mb4",
         "-N", "--raw", "eventula"],
        input=sql, capture_output=True, text=True, check=True,
    ).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def blank(value: str) -> str | None:
    return None if value in ("", "NULL", "0000-00-00 00:00:00") else value


def disambiguate(types: list[dict]) -> int:
    """Macht die Namen der Ticketarten eines Events eindeutig.

    Eventula laesst zwei Ticketarten desselben Events denselben Namen tragen:
    SX27 hat zweimal "Standard", einmal zu 35 und einmal zu 20 Euro, beide
    verkauft. Der Import erkennt eine vorhandene Ticketart an `event + name` —
    ohne diese Unterscheidung faende die zweite die erste und der Import
    verlaese sich still auf eine Ticketart zu wenig, mit falsch zugeordneten
    Tickets im Verkaufsschritt.

    Der erste Traeger des Namens behaelt ihn, jeder weitere bekommt seinen
    Preis angehaengt. Gibt zurueck, wie viele umbenannt wurden.
    """
    seen: set[str] = set()
    renamed = 0

    for entry in types:
        if entry["name"] not in seen:
            seen.add(entry["name"])
            continue

        entry["name"] = f"{entry['name']} ({entry['unit_amount_net']} EUR)"
        renamed += 1

        if entry["name"] in seen:
            raise SystemExit(f"Ticketart {entry['legacy_id']} bleibt auch mit Preis "
                             f"mehrdeutig: {entry['name']!r}")

        seen.add(entry["name"])

    return renamed


def main() -> None:
    types_by_event: dict[str, list[dict]] = {}

    for (type_id, event_id, name, price, quantity, seatable,
         sale_start, sale_end, per_user, sold) in mysql(TYPE_QUERY):
        types_by_event.setdefault(event_id, []).append({
            "legacy_id": int(type_id),
            "name": name,
            # Brutto == Netto: der Betreiber ist Kleinunternehmer nach
            # §19 UStG (Entscheidung 22.09.2026, ADR-0030), also traegt die
            # Rechnung keine Umsatzsteuerzeile und der Eventula-Preis geht
            # unveraendert als `unitAmountNet` durch.
            "unit_amount_net": price.replace(",", ""),
            # `quantity` 0 heisst in Eventula unbegrenzt, nicht ausverkauft.
            "quota": int(quantity) or None,
            "seatable": seatable == "1",
            "sales_start": blank(sale_start),
            "sales_end": blank(sale_end),
            "max_per_registration": int(per_user) or None,
            "sold": int(sold),
        })

    total_types = 0
    renamed = 0

    for event_id, name, slug, start, end, capacity, description in mysql(EVENT_QUERY):
        types = types_by_event.get(event_id, [])
        renamed += disambiguate(types)
        total_types += len(types)

        print(json.dumps({
            "legacy_id": int(event_id),
            "name": name,
            "slug": slug,
            "starts_at": start,
            "ends_at": end,
            "capacity": int(capacity),
            "description": description or None,
            # SX31 laeuft noch, alles andere ist vorbei. `concluded` und nicht
            # `archived`: die Teilnehmer sollen ihre Historie weiter sehen.
            "state": "published" if int(event_id) == LIVE_EVENT else "concluded",
            "types": types,
        }, ensure_ascii=False))

    print(f"{len(mysql(EVENT_QUERY))} Events, {total_types} Ticketarten, "
          f"{renamed} davon zur Unterscheidung umbenannt", file=sys.stderr)


if __name__ == "__main__":
    main()
