#!/usr/bin/env python3
"""Zieht die zu migrierenden Eventula-Konten als NDJSON.

Liest aus der Wegwerf-MySQL (siehe docs/runbook-eventula-migration.md, Schritt 0)
und schreibt je Konto eine Zeile nach stdout. Es verlaesst nichts den Rechner:
die Ausgabe geht per `kubectl cp` in den Pod und wird danach geloescht.

    python3 extract-users.py > work/users.ndjson
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import unicodedata
from pathlib import Path

CONTAINER = os.environ.get("EVENTULA_CONTAINER", "eventula-mig")
PASSWORD_FILE = os.environ["EVENTULA_MYSQL_PW_FILE"]

# Konventix\Identity\Profile\UsernameGenerator::FORMAT
HANDLE_FORMAT = re.compile(r"^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$")
RESERVED = {"admin", "konventix", "operator", "api", "www"}

# Eventula-Admins, die platform.brand-admin behalten: Login am oder nach dem
# 30.10.2025 (SX30). Entscheidung des Betreibers, 22.09.2026.
ADMIN_CUTOFF = "2025-10-30"

# Wer ist im Umfang: Admin, oder Purchase, oder Ticket (als Kaeufer, Manager
# oder Inhaber). Leere Konten bleiben weg.
SCOPE = """
      u.admin = 1
   or u.id in (select user_id from purchases)
   or u.id in (select user_id from tickets)
   or u.id in (select manager_id from tickets where manager_id is not null)
   or u.id in (select owner_id from tickets where owner_id is not null)
"""

QUERY = f"""
select u.id, u.firstname, u.surname, u.username, u.username_nice,
       u.steamname, u.steamid, u.steam_avatar, u.email, u.password,
       u.admin, u.locale, u.created_at, u.last_login
  from users u
 where {SCOPE}
 order by u.id
"""


def mysql(sql: str) -> list[list[str]]:
    """Fuehrt SQL in der Wegwerf-MySQL aus und gibt die Zeilen als Felder zurueck.

    `--raw` verhindert, dass der Client Tabs und Zeilenumbrueche escaped; NULL
    kommt als das Literal `NULL`, was hier keine Kollision erzeugt, weil kein
    erwartetes Feld diesen Wert als Nutzdatum traegt.
    """
    password = Path(PASSWORD_FILE).read_text().strip()
    out = subprocess.run(
        ["docker", "exec", "-i", CONTAINER, "mysql",
         "-uroot", f"-p{password}", "--default-character-set=utf8mb4",
         "-N", "--raw", "eventula"],
        input=sql, capture_output=True, text=True, check=True,
    ).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def nullable(value: str) -> str | None:
    return None if value == "NULL" or value == "" else value


def slugify(value: str) -> str:
    ascii_only = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-zA-Z0-9]+", "-", ascii_only).strip("-").lower()[:24].strip("-")


def load_overrides() -> dict[str, str]:
    path = Path(__file__).with_name("handles.tsv")
    overrides = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip() or line.startswith("#"):
            continue
        legacy_id, handle = line.split("\t")
        overrides[legacy_id.strip()] = handle.strip()
    return overrides


def handle_for(row: dict, overrides: dict[str, str], taken: set[str]) -> tuple[str, str]:
    """Waehlt den Handle und sagt, woher er kommt.

    Reihenfolge: fester Eintrag aus handles.tsv, dann `username` wie er ist,
    dann `username_nice`, dann slugifiziert. Nie aus dem Klarnamen — das
    veroeffentlichte einen Namen, den die Person nie als Handle gewaehlt hat
    (ADR-0065).
    """
    if row["id"] in overrides:
        return overrides[row["id"]], "handles.tsv"

    for candidate, source in ((row["username"], "username"),
                              (row["username_nice"], "username_nice")):
        if candidate and HANDLE_FORMAT.match(candidate) and candidate not in RESERVED:
            return candidate, source

    base = slugify(row["username_nice"] or row["username"] or "")
    if len(base) < 3:
        # Darf hier nicht mehr vorkommen: zu kurze Handles stehen in
        # handles.tsv. Faellt trotzdem einer durch, ist das ein Fehler in der
        # Datenlage und kein Fall fuer eine stille Ersatzwahl.
        raise SystemExit(f"Konto {row['id']} ({row['username']!r}) ergibt keinen "
                         f"gueltigen Handle und fehlt in handles.tsv")

    candidate, suffix = base, 2
    while candidate in RESERVED or candidate in taken:
        candidate = f"{base}-{suffix}"
        suffix += 1
    return candidate, "slugify"


def main() -> None:
    columns = ["id", "firstname", "surname", "username", "username_nice",
               "steamname", "steamid", "steam_avatar", "email", "password",
               "admin", "locale", "created_at", "last_login"]
    overrides = load_overrides()
    taken: set[str] = set()
    counts = {"total": 0, "steam": 0, "placeholder": 0, "brand_admin": 0}

    for fields in mysql(QUERY):
        row = dict(zip(columns, fields))
        handle, source = handle_for(row, overrides, taken)
        taken.add(handle)

        name = " ".join(p for p in (row["firstname"], row["surname"]) if p and p != "NULL").strip()
        steam_id = nullable(row["steamid"])
        email = nullable(row["email"])

        counts["total"] += 1
        if steam_id:
            counts["steam"] += 1
        if not email:
            counts["placeholder"] += 1

        # Admin behaelt die Rolle nur mit Login ab SX30. `last_login` steht in
        # Eventula als '0000-00-00 00:00:00', wenn nie angemeldet — der
        # Stringvergleich ordnet das korrekt vor den Stichtag ein.
        brand_admin = row["admin"] == "1" and row["last_login"][:10] >= ADMIN_CUTOFF
        if brand_admin:
            counts["brand_admin"] += 1

        print(json.dumps({
            "legacy_id": int(row["id"]),
            "name": name or row["username"],
            "username": handle,
            "username_source": source,
            "legacy_username": row["username"],
            "email": email,
            "password": nullable(row["password"]),
            "locale": row["locale"] if row["locale"] in ("de", "en") else "de",
            "created_at": nullable(row["created_at"]),
            "steam_id": steam_id,
            "steam_nickname": nullable(row["steamname"]),
            "steam_avatar": nullable(row["steam_avatar"]),
            "brand_admin": brand_admin,
        }, ensure_ascii=False))

    print(f"{counts['total']} Konten, {counts['steam']} mit Steam, "
          f"{counts['placeholder']} ohne E-Mail, {counts['brand_admin']} Brand-Admins",
          file=sys.stderr)


if __name__ == "__main__":
    main()
