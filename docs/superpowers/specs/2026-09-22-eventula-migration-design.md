# Eventula → Konventix: Datenmigration von Hand

Stand: 22.09.2026 · Zielumgebung dieses Laufs: `konventix-sund-xplosion-stage`
(`staging.sxlan.de`, Image `0.8.2`) · Ersetzt den Importer-Entwurf aus #87

## Warum kein Importer

#87 sah ein interaktives Artisan-Command mit Dry-Run, Resume und eigener
Mapping-Tabelle vor. Der Betreiber hat das am 22.09.2026 verworfen: die
Migration ist ein einmaliger Vorgang, das Werkzeug wäre nach dem Cutover tot,
und jede Zeile davon müsste trotzdem gepflegt und getestet werden.

Stattdessen: **lokale Skripte, die aus einer Wegwerf-MySQL lesen und über
`kubectl exec` in den Web-Pod schreiben.** Kein neuer Code in Konventix, kein
Release, keine neue Migration, keine API. Was bleibt, ist das Runbook —
`docs/runbook-eventula-migration.md` — mit genau den Befehlen, die am
Produktionstag laufen.

## Ablauf je Schritt

```
1. extract   python3 contrib/eventula-migration/extract-<step>.py  > work/<step>.ndjson
2. apply     kubectl cp work/<step>.ndjson  <pod>:/tmp/
             kubectl cp contrib/eventula-migration/apply-<step>.php <pod>:/tmp/
             kubectl exec <pod> -- php artisan tinker /tmp/apply-<step>.php  > map/<step>.tsv
3. verify    Zählungen auf beiden Seiten gegen die Referenzwerte unten
```

Geprüft am 22.09.2026: `kubectl exec deploy/konventix-web -- php artisan tinker`
läuft, und über die Pipe gereichtes UTF-8 kommt unverändert an
(`welt ö` → `welt ö`).

### Mapping ohne Mapping-Tabelle

Es gibt keine `legacy_id_map`-Tabelle, weil es keine Migration geben soll.
Stattdessen ist jedes `apply`-Skript **idempotent auf einem natürlichen
Schlüssel** (E-Mail für Nutzer, `brand+slug` für Events, `event+name` für
Registrierungsarten) und **druckt** `legacy_id <TAB> ulid` nach stdout. Die
lokal aufgefangene Datei `map/<step>.tsv` ist die Mapping-Tabelle: sie macht
Wiederholungsläufe gefahrlos und ist die Eingabe des späteren Verkaufsschritts.

### Sicherheit

- **Mail kann nicht raus.** Staging zeigt auf `mailpit-smtp.mailpit.svc.cluster.local:1025`,
  einen In-Cluster-Sink. Vor jedem Lauf gegen Produktion ist das eigens zu prüfen
  (C2: Mail-Driver `log` oder Queue pausiert).
- **`UserRegistered` hat genau einen Listener**, `Admission\Listeners\ProjectPerson`
  (Personen-Projektion, idempotent). Kein Mailversand. Das Ereignis wird
  gefeuert, weil die Projektion für Check-in und Admission gebraucht wird.
- **`AuthMailer::sendEmailMissingNotice()` wird nicht aufgerufen.** Es zielt auf
  `@pending.invalid`, das Messaging auf dem Mail-Kanal ohnehin verweigert
  (`SendMessage::refusalFor()`). Der Hinweis aus #293 erscheint beim ersten
  Steam-Login in der App.
- **Der Dump verlässt den Rechner nicht.** MySQL-Container `eventula-mig` an
  `127.0.0.1:13306`, Auszüge nur im Scratchpad der Sitzung. Beides wird am Ende
  gelöscht. Nichts davon geht ins Repo.

## Quelldaten (Dump `2026-09-22-001.sql`, 22.09.2026 09:44)

MySQL 8.4, 59 Tabellen, `utf8mb3_unicode_ci`. Die Bytes sind sauberes UTF-8
(`C3B6` = ö); ein `latin1`-Client zeigt Mojibake, der Datenbestand ist in
Ordnung. Alle Werkzeuge mit `--default-character-set=utf8mb4` fahren.

Die Analyse vom 18.09.2026 (#87) reproduziert sich unverändert auf diesem Dump.
Die Nutzerzahl ist von 930 auf 973 gestiegen; die 43 neuen Konten sind
Spam-Anmeldungen ohne Login und ohne Kauf, also außerhalb des Umfangs.

### Referenzwerte je Event

| id | Event | Tickets | gültig | widerrufen | Staff | Frei | transferiert | ohne Purchase |
|---|---|---|---|---|---|---|---|---|
| 2 | SX26 (11/2022) | 105 | 105 | 0 | 14 | 2 | 0 | 16 |
| 3 | SX27 (04/2023) | 53 | 53 | 0 | 0 | 3 | 3 | 3 |
| 4 | SX28 (11/2023) | 111 | 110 | 1 | 0 | 1 | 1 | 1 |
| 6 | SX29 (11/2024) | 167 | 114 | 53 | 0 | 2 | 0 | 2 |
| 7 | SX30 (11/2025) | 87 | 81 | 6 | 11 | 2 | 0 | 0 |
| 9 | SX31 (11/2026) | 24 | 24 | 0 | 0 | 0 | 0 | 0 |
| 1, 5, 8 | Test/Draft | 29 | — | — | — | — | — | — |

Events 1 (`INTERNAL TESTING - NO BUY!!!`), 5 (`Attendee-Mülltonne`) und
8 (`SX29 Testlan`) werden **nicht** migriert.

## Schritt 1 — Nutzer

### Umfang: 253 von 973

Importiert wird, wer Admin ist **oder** eine Purchase **oder** ein Ticket hat
(als `user_id`, `manager_id` oder `owner_id`). Leere Konten bleiben weg.

Der ursprüngliche Entwurf zählte auch geschenkte Sitzplätze
(`event_seating.gifted_user_id`) als Grund. Das wurde am 22.09.2026 gestrichen;
gemessen ändert es nichts — das Kriterium hat nie jemanden hinzugefügt, die
Zahl bleibt 253.

| Merkmal | Anzahl |
|---|---|
| mit bcrypt-Passwort | 158 |
| mit Steam-Verknüpfung | 143 |
| nur Steam, kein Passwort | 95 |
| ohne E-Mail-Adresse | 14 |
| gesperrt | 0 |
| Admin-Flag in Eventula | 29 |

E-Mail- und Benutzernamen-Kollisionen (case-insensitive): jeweils **0**.

### Feldabbildung

| Konventix | Eventula |
|---|---|
| `name` | `firstname + ' ' + surname`, ersatzweise `username` |
| `username` | siehe Handle-Regeln |
| `email` | `email`, für die 14 ohne Adresse `PlaceholderEmail::forSteam(steamid)` |
| `password` | der rohe `$2y$`-bcrypt-Hash; Laravel verifiziert ihn direkt |
| `email_verified_at` | gesetzt für die 239 mit echter Adresse, **leer für die 14 Platzhalter** |
| `locale` | `locale` |
| `state` | `active` (niemand ist gesperrt) |
| `social_identities` | je `steamid` eine Zeile `provider=steam`, mit `steamname` und `steam_avatar` |
| Guthaben (`credit_total`, 213 `credit_log`-Zeilen) | **nicht übernommen** |

`email_verified_at` bleibt bei den Platzhaltern bewusst leer: erst damit landen
diese Konten nach dem ersten Steam-Login im `SetAccountEmail`-Fluss aus #293.
Der Entwurf vom 18.09. sagte „für alle setzen" — das widersprach #293 und ist
hiermit zugunsten von #293 aufgelöst.

### Handle-Regeln

`users.username` ist `NOT NULL` mit `unique(lower(username))`;
`UsernameGenerator::FORMAT` verlangt `^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$`.

1. `username` genügt dem Format → unverändert (51 Konten)
2. sonst `username_nice`, wenn es genügt (161 Konten)
3. sonst slugifizieren: NFKD → ASCII, alles Übrige zu `-`, auf 24 Zeichen (41 Konten)

**Nicht** aus dem Klarnamen generieren. `Markus.Kohn → markus-kohn` ist in
Ordnung, weil die Person diesen Namen selbst als Handle gewählt hat; aus
`firstname`/`surname` einen Handle zu bauen, veröffentlicht einen Klarnamen,
den die Person nie als Handle wollte (ADR-0065: Teilnehmerflächen zeigen den
Handle).

Sechs Konten brauchen eine Entscheidung und stehen darum fest in
`contrib/eventula-migration/handles.tsv`:

| Eventula-Id | Eventula-Username | Handle | Warum |
|---|---|---|---|
| 45 | *(E-Mail-Adresse)* | `fedcellar` | Slugify ergäbe `<lokalteil>-<domain>-<tld>` und machte eine E-Mail-Adresse zum öffentlichen Handle |
| 211 | *(E-Mail-Adresse)* | `atzetooon` | dito |
| 304 | *(E-Mail-Adresse)* | `timo-stern` | dito |
| 178 | `NJ` | `nj-sx` | zwei Zeichen, unter der Mindestlänge 3 |
| 252 | `NN` | `nn-sx` | dito |
| 402 | `¯\_(ツ)_/¯` | `trollusername` | nicht slugifizierbar; Handle vom Betreiber vergeben |

Alle sechs haben Käufe in Eventula und sind darum im Umfang; sie lassen sich
nicht durch Weglassen lösen.

Die drei Adressen selbst stehen hier nicht — der Handle ist die Entscheidung,
die Adresse ist das Datum der Person. Nachzusehen sind sie im Dump unter den
genannten Ids.

### Rollen

Die Brand `sxlan` existiert auf Staging, hat aber **noch keine Rollen** — die
einzige vorhandene Rolle ist „Operator Admin" auf der Operator-Brand. Schritt 1
führt darum zuerst `MaterializeDefaultRoles` für `sxlan` aus; das erzeugt
`platform.brand-admin` („Brand Admin", ADR-0045).

Von den 29 Eventula-Admins bekommen **17** diese Rolle: alle mit einem Login am
oder nach dem 30.10.2025 (SX30). Entscheidung des Betreibers vom 22.09.2026.

Behalten: `Cheeseburglar`, `Nordisch`, `Mawiguk0`, `Treasure`, `Blinded`,
`icewindow`, `BellaTheBatPony`, `Bigblacky03`, `TiRo`, `Kryp7x`, `Tenni`,
`Freya`, `Lord_Rolf`, `Cpersy20`, `salad`, `ElPaca`, `Alphi`.

Als einfache Nutzer importiert (Konto und Historie bleiben, keine Rechte):
`Olaf`, `Valendas`, `acoknight`, `Biohörnchen`, `juypra`, `Mawis-test2`,
`karo`, `derconno`, `aarux`, `Commander`, `Kiba`, `das_ichdu`.

`Olaf` (letzter Login 16.10.2025) stand zuerst auf der Behalten-Liste, erfüllt
den Stichtag aber nicht; der Betreiber hat am 22.09.2026 entschieden, dass die
Regel gilt und nicht die Liste.

> `das_ichdu` (Eventula-Id 932, angelegt am 05.03.2026) trägt `admin=1` und hat
> sich **nie angemeldet**. Das ist unabhängig von dieser Migration auf der
> Eventula-Seite zu klären.

## Schritt 2 — Events und Registrierungsarten

| Eventula | Konventix |
|---|---|
| events 2, 3, 4, 6, 7 | `Event` auf Brand `sxlan`, `state = concluded`, `concluded_at` = `end`, mit `slug`, `name`, `starts_at`/`ends_at`, ohne Venue |
| event 9 (SX31) | gleicher Weg, danach `state = published` und **von Hand korrigiert** |
| events 1, 5, 8 | nicht importiert |
| die 61 `ticket_types` dieser sechs Events | je ein `RegistrationType`: `settlement = external`, `price_declaration` aus `price`, Grant `admission`/participant/1, `visibility = link` |

Eventula lässt zwei Ticketarten desselben Events denselben Namen tragen — SX27
hat zweimal „Standard", zu 35 und zu 20 Euro, beide verkauft. Der Import
erkennt eine vorhandene Ticketart an `event + name`; der Auszug macht die
Namen darum vorher eindeutig, indem jeder weitere Träger seinen Preis
angehängt bekommt. Ohne das entstünde eine Ticketart zu wenig und sechs
Tickets hingen im Verkaufsschritt am falschen Preis.

`timezone` ist `Europe/Berlin` (ADR-0027: Eventzeiten sind Wanduhrzeit).

**Preise sind Endpreise.** Eventula speichert, was der Käufer zahlt; Konventix
deklariert `unitAmountNet` und lässt Commerce die Steuer rechnen (ADR-0017).
Der Sund-Xplosion e. V. ist Kleinunternehmer nach §19 UStG (Entscheidung des
Betreibers, 22.09.2026), also trägt die Rechnung keine Umsatzsteuerzeile und
der Eventula-Preis geht unverändert durch. Der Schalter sitzt auf dem
Betreiber, nicht auf der Brand (ADR-0030), und gehört vor dem Import geprüft —
stünde er falsch, würde aus 45,00 ein Endpreis von 53,55.

`settlement = external` ist der Kern: darüber entsteht keine
Commerce-Bestellung und damit keine Rechnungsnummer für Umsätze, die anderswo
abgerechnet wurden.

Auch SX31 wird erst als `external` und nicht-öffentlich angelegt. Das ist
Absicht: nichts wird verkäuflich, bevor der Betreiber Settlement, Preise,
Kontingente, Seating-Grant und Sichtbarkeit von Hand gesetzt hat. Das Runbook
führt diesen Handgriff als Checkliste.

## Was dieser Lauf nicht tut

Verkäufe — Registrierungen, Bestellungen, Zahlungen, Sitzplätze — sind
**Schritt 3** und nicht Teil dieses Laufs. Zwei Gründe stehen ohnehin im Weg:

- `orders.legacy_source` und `orders.legacy_reference` aus #295 gibt es in
  0.8.2 nicht. Ohne diesen Marker ist eine importierte Bestellung nicht von
  einer Konventix-Bestellung zu unterscheiden, und die Sperre gegen Rechnung
  und Erstattung fehlt.
- Die Stripe-Sandbox der Staging-Instanz kennt die Produktionsvorgänge nicht,
  und PayPal wird nicht mehr direkt unterstützt. Importierte Zahlungen tragen
  darum nur `provider` und `provider_transaction_id` als Klartext, ohne
  Verbindung zum PSP.

Ebenfalls nicht migriert, jeweils mit Zahl im Prüfbericht auszuweisen: Shop
(53 Bestellungen), Guthaben (43 Aufladungen), 55 verwaiste Purchases aus
12/2021–03/2022, die Test-Events und das Seating von SX26–SX30.

## Ergebnis

- `docs/runbook-eventula-migration.md` — das Runbook für den Produktionstag:
  je Schritt die endgültigen Befehle, die Referenzwerte zur Gegenprüfung, die
  Handgriff-Checklisten und der Rückweg. Keine Sackgassen.
- `contrib/eventula-migration/` — die Skripte, auf die das Runbook verweist.
  Sie enthalten keine Daten.
