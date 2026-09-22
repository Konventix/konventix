# Runbook: Eventula → Konventix

Der Ablauf der Datenmigration, Schritt für Schritt, mit den Befehlen, die
wirklich laufen. Der Entwurf dahinter steht in
`docs/superpowers/specs/2026-09-22-eventula-migration-design.md`; warum es
keinen Importer gibt, steht dort im ersten Abschnitt.

Erprobt am 22.09.2026 gegen `konventix-sund-xplosion-stage` (Image `0.8.2`).
Jeder Schritt nennt am Ende seine Referenzwerte — stimmen die nicht, ist der
Schritt nicht fertig.

## Vorbedingungen

- Der Dump des Altsystems liegt lokal (`<dump>.sql`, MySQL 8.4).
- `kubectl` erreicht den Zielnamespace, `docker` läuft.
- **Mailversand kann nichts nach draußen schicken.** Auf Staging zeigt
  `MAIL_HOST` auf `mailpit-smtp.mailpit.svc.cluster.local`. Vor einem Lauf
  gegen Produktion: Mail-Driver auf `log` oder Queue pausieren (C2), und das
  vor dem ersten Schritt prüfen, nicht danach.
- Die Ziel-Brand existiert (`sxlan`).
- Der §19-Schalter des Betreibers steht richtig — siehe Schritt 2.1.

Umgebung für alle Schritte:

```bash
export KUBECONFIG=~/.kube/sxcs.yaml
export NS=konventix-sund-xplosion-stage
export WORK=$(mktemp -d)          # Auszüge und Zuordnungen; am Ende löschen
mkdir -p "$WORK/work" "$WORK/map"

# Als Funktionen, nicht als Variablen: zsh zerlegt einen mehrwortigen
# Variablenwert nicht in Argumente, `$KCTX exec …` sucht dann ein Programm
# namens "kubectl --context rancher -n …" und schreibt eine leere Datei,
# ohne sich zu beschweren. In einer Gegenprüfung ist das eine falsche
# Entwarnung.
kx()  { kubectl --context rancher -n "$NS" "$@"; }
psq() { kx exec konventix-db-1 -c postgres -- psql -U postgres -d konventix "$@"; }

export POD=$(kx get pod -l app.kubernetes.io/component=web -o name | head -1 | cut -d/ -f2)
```

## Schritt 0 — Dump in eine Wegwerf-MySQL

Der Dump wird nie in die Zieldatenbank gespielt. Er wird lokal gelesen, und
zwar in einem Container, der danach verschwindet.

```bash
openssl rand -hex 16 > "$WORK/mysql-root-pw"
chmod 600 "$WORK/mysql-root-pw"

docker run -d --name eventula-mig \
  -e MYSQL_ROOT_PASSWORD="$(cat "$WORK/mysql-root-pw")" \
  -e MYSQL_DATABASE=eventula \
  -p 127.0.0.1:13306:3306 \
  mysql:8.4 --skip-log-bin

until docker exec eventula-mig mysqladmin ping -uroot \
      -p"$(cat "$WORK/mysql-root-pw")" --silent 2>/dev/null; do sleep 2; done

docker exec -i eventula-mig mysql -uroot -p"$(cat "$WORK/mysql-root-pw")" \
  eventula < <dump>.sql

export EVENTULA_MYSQL_PW_FILE="$WORK/mysql-root-pw"
cd konventix-poc/contrib/eventula-migration
```

An `127.0.0.1` gebunden, damit die Produktionsdaten nicht im Netz stehen.

**Jeder** MySQL-Aufruf braucht `--default-character-set=utf8mb4` — die Skripte
tun das. Die Tabellen sind `utf8mb3_unicode_ci` mit sauberem UTF-8; ein Client
mit der Standard-Collation `latin1_swedish_ci` zeigt `Bioh?rnchen` statt
`Biohörnchen` und schriebe den Müll auch so weiter.

Referenz: 59 Tabellen, `select count(*) from users` = 973
(Dump `2026-09-22-001.sql`).

## Schritt 1 — Nutzer

### 1.1 Rollen der Brand anlegen

Eine frische Instanz hat auf der Brand keine Rollen; ohne sie gibt es kein
Ziel für die Admin-Zuordnung.

```bash
kx exec $POD -c web -- php artisan permissions:sync --no-interaction
psq -c "select name, default_key from roles r
          join brands b on b.id=r.brand_id where b.slug='sxlan';"
```

Erwartet: `Default roles for [SXLAN]: 6 created`, darunter
`Brand Admin / platform.brand-admin`.

### 1.2 Konten ausziehen

```bash
python3 extract-users.py > "$WORK/work/users.ndjson"
```

Meldet nach stderr: `253 Konten, 143 mit Steam, 14 ohne E-Mail, 17 Brand-Admins`.

Bricht das Skript mit „ergibt keinen gueltigen Handle" ab, fehlt ein Konto in
`handles.tsv`. Das ist Absicht: ein Handle, den die Daten nicht hergeben, wird
entschieden und aufgeschrieben, nicht stillschweigend geraten.

### 1.3 Konten anlegen

```bash
kx cp "$WORK/work/users.ndjson" $POD:/tmp/users.ndjson -c web
kx cp apply-users.php            $POD:/tmp/apply-users.php -c web
kx exec $POD -c web -- php artisan tinker /tmp/apply-users.php \
  > "$WORK/map/users.raw" 2> "$WORK/map/users.err"

# tinker hängt einen Prompt an; nur die Zuordnungszeilen behalten
grep -E '^[0-9]+\s' "$WORK/map/users.raw" > "$WORK/map/users.tsv"
cat "$WORK/map/users.err"
```

Erwartet auf einer leeren Instanz: `253 angelegt, 0 schon vorhanden,
143 mit Steam, 14 Platzhalter-Adressen, 17 Brand-Admins`.

`map/users.tsv` ist die Mapping-Tabelle `eventula_user_id → ULID → Handle →
created|existing`. **Sie wird gebraucht**, sobald Bestellungen migriert werden.

Der Lauf ist idempotent auf der E-Mail-Adresse: ein zweiter Durchlauf legt
nichts doppelt an und meldet `existing`. Bricht er mittendrin ab, einfach
wiederholen.

### 1.4 Gegenprüfung

```bash
psq -c "
select 'users imported' as check, (count(*)-1)::text from users
union all select 'with password',     count(*)::text from users where password is not null
union all select 'placeholder email', count(*)::text from users where email like '%@pending.invalid'
union all select 'unverified',        count(*)::text from users where email_verified_at is null
union all select 'steam identities',  count(*)::text from social_identities where provider='steam'
union all select 'brand memberships', count(*)::text from brand_memberships
union all select 'brand admins',      count(*)::text from model_has_roles mr
           join roles r on r.id=mr.role_id where r.default_key='platform.brand-admin'
union all select 'person projections', count(*)::text from admission_people
union all select 'state != active',    count(*)::text from users where state <> 'active';"
```

| Prüfung | Sollwert | Warum |
|---|---|---|
| users imported | 253 | Umfang; `-1` ist der Operator-Admin der Instanz |
| with password | 159 | 158 aus Eventula + Operator-Admin |
| placeholder email | 14 | Steam-Konten ohne Adresse (#293) |
| unverified | 14 | genau die Platzhalter, sonst niemand |
| steam identities | 143 | |
| brand memberships | 253 | jedes importierte Konto kennt die Brand |
| brand admins | 17 | Login seit 30.10.2025 (SX30) |
| person projections | 253 | `UserRegistered` ist bei jedem angekommen |
| state != active | 0 | in Eventula ist niemand gesperrt |

Dann feldweise gegen die Quelle — Name, E-Mail, Passwort-Hash und SteamID
jedes einzelnen Kontos:

```bash
psq -At -F$'\t' -c "
select u.username, u.name, u.email, coalesce(u.password,''), coalesce(s.provider_user_id,'')
  from users u left join social_identities s on s.user_id=u.id
 where u.username <> 'operator-admin' order by u.username;" > "$WORK/kx-users.tsv"

python3 verify-users.py "$WORK/work/users.ndjson" "$WORK/kx-users.tsv"
```

Erwartet: `verglichen: 253 Konten, Abweichungen: 0`. Jede Abweichung ist zu
erklären, sonst gilt C12 (Abbruch).

### Was Schritt 1 bewusst nicht tut

- **Kein Guthaben.** `credit_total` und die 213 `credit_log`-Zeilen bleiben in
  Eventula.
- **Keine Mail.** `UserRegistered` hat genau einen Listener
  (`Admission\ProjectPerson`), und der schreibt nur die Personen-Projektion.
  Die Begrüßungsmail an alle importierten Konten ist ein eigener, gewollter
  Schritt (#158, C18) und der Point of no return.
- **Keine Sitzungen, keine Tokens, keine Passwort-Resets.** Wer ein Passwort
  hatte, hat es weiter; der Rest kommt über Steam oder „Passwort vergessen".

## Schritt 2 — Events und Registrierungsarten

### 2.1 §19-Schalter des Betreibers prüfen

Eventulas Preise sind Endpreise. Konventix deklariert `unitAmountNet` und
lässt Commerce die Steuer rechnen (ADR-0017). Der Sund-Xplosion e. V. ist
Kleinunternehmer nach §19 UStG (Entscheidung 22.09.2026), also trägt die
Rechnung keine Umsatzsteuerzeile und der Eventula-Preis geht unverändert
durch — 45,00 deklariert, 45,00 bezahlt.

Steht der Schalter falsch, würde aus 45,00 ein Endpreis von 53,55. Vor dem
Import prüfen:

```bash
kx exec $POD -c web -- php artisan tinker --execute='
  $o = \Konventix\Platform\Models\Operator::query()->firstOrFail();
  echo $o->name." small_business_scheme=".var_export($o->small_business_scheme,true)."\n";'
```

Erwartet `true`. Gesetzt wird er in der Konsole unter den
Betreiber-Einstellungen; §19 ist eine Wahl des Betreibers gegenüber dem
Finanzamt, keine Eigenschaft der Brand (ADR-0030).

### 2.2 Events ausziehen

```bash
python3 extract-events.py > "$WORK/work/events.ndjson"
```

Erwartet: `6 Events, 61 Ticketarten, 1 davon zur Unterscheidung umbenannt`.

Migriert werden die Events 2, 3, 4, 6, 7 (SX26–SX30) und 9 (SX31). **Nicht**
dabei: 1 (`INTERNAL TESTING - NO BUY!!!`), 5 (`Attendee-Mülltonne`) und
8 (`SX29 Testlan`) — Werkzeuge des Altsystems, keine Veranstaltungen.

Zu der einen Umbenennung: SX27 hat zwei Ticketarten namens „Standard", zu 35
und zu 20 Euro, beide verkauft (14 und 6 Tickets). Der Import erkennt eine
vorhandene Ticketart an `event + name`; ohne Unterscheidung fände die zweite
die erste, es entstünde eine Ticketart zu wenig, und im Verkaufsschritt hingen
sechs Tickets am falschen Preis. Der erste Träger des Namens behält ihn, jeder
weitere bekommt seinen Preis angehängt: `Standard (20.00 EUR)`.

### 2.3 Events anlegen

```bash
kx cp "$WORK/work/events.ndjson" $POD:/tmp/events.ndjson -c web
kx cp apply-events.php            $POD:/tmp/apply-events.php -c web
kx exec $POD -c web -- php artisan tinker /tmp/apply-events.php \
  > "$WORK/map/events.raw" 2> "$WORK/map/events.err"

grep -E '^(event|type)\s' "$WORK/map/events.raw" > "$WORK/map/events.tsv"
cat "$WORK/map/events.err"
```

Erwartet auf einer leeren Instanz:
`6 Events angelegt (0 vorhanden), 61 Ticketarten angelegt (0 vorhanden)`.

`map/events.tsv` hält beide Zuordnungen — `event` und `type`, je mit
Eventula-Id und ULID. **Die Typ-Zeilen sind die Eingabe des
Verkaufsschritts.**

Jede Ticketart kommt mit `settlement = external` herein. Das ist der Kern des
ganzen Vorgangs: `external` heißt „anderswo abgerechnet", und damit entsteht
über die Ereigniskette keine Commerce-Bestellung und keine GoBD-Rechnungsnummer
für Umsätze aus 2022–2026 (#295, ADR-0031).

Auch SX31 kommt `unlisted` und mit `settlement = external` herein. Nichts wird
sichtbar oder verkäuflich, bevor 2.5 gelaufen ist.

### 2.4 Gegenprüfung

```bash
psq -At -F$'\t' -c "select slug, state, starts_at, ends_at from events order by slug;" \
  > "$WORK/kx-events.tsv"
psq -At -F$'\t' -c "
select e.slug, rt.name, rt.price_declaration->>'unitAmountNet', rt.settlement
  from registration_types rt join events e on e.id=rt.event_id
 order by e.slug, rt.name;" > "$WORK/kx-types.tsv"

python3 verify-events.py "$WORK/work/events.ndjson" "$WORK/kx-events.tsv" "$WORK/kx-types.tsv"
```

Erwartet: `verglichen: 6 Events, 61 Ticketarten, Abweichungen: 0`.

| Event | Zustand | Ticketarten |
|---|---|---|
| sxlan-26 (11/2022) | concluded | 7 |
| sx27 (04/2023) | concluded | 5 |
| sx28 (11/2023) | concluded | 8 |
| sx29 (11/2024) | concluded | 14 |
| sx30 (10/2025) | concluded | 16 |
| sx31 (11/2026) | published | 11 |

`concluded` und nicht `archived`: die Teilnehmer sollen ihre Historie weiter
sehen.

### 2.5 SX31 von Hand richtigstellen

Der Import bringt SX31 auf denselben Weg wie die Historie — bewusst
zurückhaltend. Was das laufende Event wirklich braucht, entscheidet ein
Mensch in der Konsole. Bis diese Liste abgearbeitet ist, ist SX31 nicht
verkaufsbereit:

- [ ] **Sichtbarkeit** des Events von `unlisted` auf `public`.
- [ ] **Settlement** der elf Ticketarten von `external` auf `instant` — erst
      damit entsteht bei einem Kauf eine Konventix-Bestellung mit Rechnung.
      Die fünf „SX30 Special"-Varianten sind Vorverkauf und ausverkauft; sie
      müssen als Ziel existieren, aber nicht verkäuflich sein.
- [ ] **Sichtbarkeit** der verkäuflichen Ticketarten von `link` auf `public`.
- [ ] **Kontingente** setzen (Eventula: 160 Gesamtkapazität, `quantity` je
      Ticketart). Der Import legt keine an — ein Kontingent ist die Obergrenze
      und gehört gesetzt, nicht abgeleitet (#282).
- [ ] **Seating-Grant** auf den Ticketarten, die einen Platz geben sollen, und
      der Sitzplan. In Eventula hat SX31 einen Plan „Mensa" **ohne eine
      einzige Sitzzeile** — zu migrieren ist hier nichts, der Plan wird neu
      gebaut. Wichtig: der Grant muss stehen, **bevor** Anmeldungen bestätigt
      werden. Eine bereits bestätigte Anmeldung auf einer Ticketart, die den
      Grant erst danach bekommt, erhält keinen Platzanspruch, und es gibt
      keinen Nachzieh-Befehl (`docs/operations.md`, „Cutover hand-over").
- [ ] **Verkaufsfenster** (`sales_start`/`sales_end`) gegen Eventula prüfen.
- [ ] **Preise** gegen Eventula prüfen: 35 / 42 / 15 / 22 / 230 (SX30 Special)
      und 45 / 52 / 20 / 27 / 290 / 15.

### Was Schritt 2 bewusst nicht tut

- **Keine Venues.** Eventula führt `event_venues`; Konventix bekommt die
  Events ohne Ort.
- **Keine Kontingente.** Siehe 2.5.
- **Kein Seating.** Die 394 belegten Plätze aus SX26–SX30 bleiben in Eventula;
  SX31 hat keine.
- **Keine Turniere, Zeitpläne, Galerien, News.** Timetables sind ein eigenes
  Vorhaben (#106).

## Schritt 3 — Verkäufe

Noch nicht gelaufen. Zwei Dinge stehen davor:

- `orders.legacy_source` und `orders.legacy_reference` aus **#295** gibt es in
  0.8.2 nicht. Ohne diesen Marker ist eine importierte Bestellung nicht von
  einer Konventix-Bestellung zu unterscheiden, und die Sperre gegen Rechnung
  und Erstattung fehlt.
- Die Stripe-Sandbox kennt die Produktionsvorgänge nicht, und PayPal wird
  nicht mehr direkt unterstützt. Importierte Zahlungen können nur `provider`
  und `provider_transaction_id` als Klartext tragen.

Die Zuordnungen aus `map/users.tsv` und `map/events.tsv` sind dafür
aufzubewahren.

## Aufräumen

Nach jedem Lauf, auch nach einem abgebrochenen:

```bash
kx exec $POD -c web -- rm -f /tmp/users.ndjson /tmp/events.ndjson \
                             /tmp/apply-users.php /tmp/apply-events.php
docker rm -f eventula-mig
# map/*.tsv vorher wegsichern, wenn Schritt 3 noch aussteht
rm -rf "$WORK"
```

Die Auszüge enthalten Klarnamen, Adressen und Passwort-Hashes. Sie gehören
weder ins Repo noch in ein Ticket noch in einen Chat — nur `map/*.tsv` ist
aufzubewahren, und auch das nur bis der Cutover durch ist.
