#!/usr/bin/env python3
"""Vergleicht die importierten Events und Ticketarten mit der Quelle.

    python3 verify-events.py work/events.ndjson kx-events.tsv kx-types.tsv

`kx-events.tsv`: slug, state, starts_at, ends_at
`kx-types.tsv`:  slug, name, unitAmountNet, settlement

Beendet sich mit 1, sobald etwas abweicht.
"""

from __future__ import annotations

import json
import sys


def main() -> int:
    source_path, events_path, types_path = sys.argv[1], sys.argv[2], sys.argv[3]

    events = [json.loads(line) for line in open(source_path, encoding="utf-8")]

    target_events = {}
    for line in open(events_path, encoding="utf-8"):
        fields = line.rstrip("\n").split("\t")
        if len(fields) == 4:
            target_events[fields[0]] = fields

    target_types: dict[tuple[str, str], list[str]] = {}
    for line in open(types_path, encoding="utf-8"):
        fields = line.rstrip("\n").split("\t")
        if len(fields) == 4:
            target_types[(fields[0], fields[1])] = fields

    problems: list[str] = []
    expected_types = 0

    for event in events:
        slug = event["slug"]
        found = target_events.get(slug)

        if found is None:
            problems.append(f"{slug}: fehlt in Konventix")
            continue

        if found[1] != event["state"]:
            problems.append(f"{slug}: Zustand {found[1]!r} statt {event['state']!r}")
        if found[2][:19] != event["starts_at"]:
            problems.append(f"{slug}: Beginn {found[2][:19]!r} statt {event['starts_at']!r}")
        if found[3][:19] != event["ends_at"]:
            problems.append(f"{slug}: Ende {found[3][:19]!r} statt {event['ends_at']!r}")

        for entry in event["types"]:
            expected_types += 1
            key = (slug, entry["name"])
            found_type = target_types.get(key)

            if found_type is None:
                problems.append(f"{slug} / {entry['name']}: fehlt in Konventix")
                continue

            if found_type[2] != entry["unit_amount_net"]:
                problems.append(f"{slug} / {entry['name']}: Preis {found_type[2]} "
                                f"statt {entry['unit_amount_net']}")
            if found_type[3] != "external":
                # Waere das anders, zoege eine bezahlte Anmeldung eine
                # Rechnungsnummer fuer einen anderswo abgerechneten Umsatz.
                problems.append(f"{slug} / {entry['name']}: Settlement "
                                f"{found_type[3]!r} statt 'external'")

    for slug, name in set(target_types) - {(e["slug"], t["name"]) for e in events for t in e["types"]}:
        problems.append(f"{slug} / {name}: in Konventix, aber nicht im Auszug")

    print(f"verglichen: {len(events)} Events, {expected_types} Ticketarten, "
          f"Abweichungen: {len(problems)}")
    for problem in problems[:20]:
        print(f"  {problem}")
    if len(problems) > 20:
        print(f"  ... und {len(problems) - 20} weitere")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
