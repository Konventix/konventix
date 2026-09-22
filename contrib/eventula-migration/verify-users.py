#!/usr/bin/env python3
"""Vergleicht jedes importierte Konto feldweise mit der Quelle.

Die Zaehlungen aus der Gegenpruefung sagen, dass gleich viele Zeilen da sind.
Dieses Skript sagt, dass es dieselben sind: Name, E-Mail, Passwort-Hash und
SteamID jedes einzelnen Kontos.

    python3 verify-users.py work/users.ndjson kx-users.tsv

Beendet sich mit 1, sobald etwas abweicht — damit ein Lauf in einer Kette
(`&&`) nicht stillschweigend weitergeht.
"""

from __future__ import annotations

import json
import sys


def main() -> int:
    source_path, target_path = sys.argv[1], sys.argv[2]

    source = {}
    for line in open(source_path, encoding="utf-8"):
        row = json.loads(line)
        source[row["username"]] = row

    target = {}
    for line in open(target_path, encoding="utf-8"):
        fields = line.rstrip("\n").split("\t")
        if len(fields) == 5:
            target[fields[0]] = fields

    problems: list[tuple[str, str]] = []

    for handle, row in source.items():
        found = target.get(handle)

        if found is None:
            problems.append((handle, "fehlt in Konventix"))
            continue

        # Die 14 Konten ohne Adresse tragen in Konventix den Platzhalter aus
        # #293 — in der Quelle steht dort nichts, also wird er hier gebildet.
        expected_email = row["email"] or f"steam-{row['steam_id']}@pending.invalid"

        if found[1] != row["name"]:
            problems.append((handle, f"Name {found[1]!r} statt {row['name']!r}"))
        if found[2] != expected_email:
            problems.append((handle, f"E-Mail {found[2]!r} statt {expected_email!r}"))
        if found[3] != (row["password"] or ""):
            # Der Hash selbst wird nie gedruckt.
            problems.append((handle, "Passwort-Hash weicht ab"))
        if found[4] != (row["steam_id"] or ""):
            problems.append((handle, f"SteamID {found[4]!r} statt {row['steam_id']!r}"))

    for handle in set(target) - set(source):
        problems.append((handle, "in Konventix, aber nicht im Auszug"))

    print(f"verglichen: {len(source)} Konten, Abweichungen: {len(problems)}")
    for handle, why in problems[:20]:
        print(f"  {handle}: {why}")
    if len(problems) > 20:
        print(f"  ... und {len(problems) - 20} weitere")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
