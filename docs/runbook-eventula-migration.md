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

Umgebung für alle Schritte:

```bash
export KUBECONFIG=~/.kube/sxcs.yaml
export KCTX="kubectl --context rancher -n konventix-sund-xplosion-stage"
export POD=$($KCTX get pod -l app.kubernetes.io/component=web -o name | head -1 | cut -d/ -f2)
export WORK=$(mktemp -d)          # Auszüge und Zuordnungen; am Ende löschen
mkdir -p "$WORK/work" "$WORK/map"
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
```

An `127.0.0.1` gebunden, damit die Produktionsdaten nicht im Netz stehen.

**Jeder** MySQL-Aufruf braucht `--default-character-set=utf8mb4`. Die Tabellen
sind `utf8mb3_unicode_ci` mit sauberem UTF-8; ein Client mit der
Standard-Collation `latin1_swedish_ci` zeigt `Bioh?rnchen` statt `Biohörnchen`
und schriebe den Müll auch so weiter.

Referenz: 59 Tabellen, `select count(*) from users` = 973
(Dump `2026-09-22-001.sql`).

## Schritt 1 — Nutzer

### 1.1 Rollen der Brand anlegen

Eine frische Instanz hat auf der Brand keine Rollen; ohne sie gibt es kein
Ziel für die Admin-Zuordnung.

```bash
$KCTX exec $POD -c web -- php artisan permissions:sync --no-interaction
```

Erwartet: `Default roles for [SXLAN]: 6 created`. Prüfen:

```bash
$KCTX exec konventix-db-1 -c postgres -- \
  psql -U postgres -d konventix -c \
  "select name, default_key from roles r join brands b on b.id=r.brand_id where b.slug='sxlan';"
```

### 1.2 Konten ausziehen

```bash
cd konventix-poc/contrib/eventula-migration
EVENTULA_MYSQL_PW_FILE="$WORK/mysql-root-pw" \
  python3 extract-users.py > "$WORK/work/users.ndjson"
```

Meldet nach stderr: `253 Konten, 143 mit Steam, 14 ohne E-Mail, 17 Brand-Admins`.

Bricht das Skript mit „ergibt keinen gueltigen Handle" ab, fehlt ein Konto in
`handles.tsv`. Das ist Absicht: ein Handle, den die Daten nicht hergeben, wird
entschieden und aufgeschrieben, nicht stillschweigend geraten.

### 1.3 Konten anlegen

```bash
$KCTX cp "$WORK/work/users.ndjson" $POD:/tmp/users.ndjson -c web
$KCTX cp apply-users.php            $POD:/tmp/apply-users.php -c web
$KCTX exec $POD -c web -- php artisan tinker /tmp/apply-users.php \
  > "$WORK/map/users.raw" 2> "$WORK/map/users.err"

# tinker haengt einen Prompt an; nur die Zuordnungszeilen behalten
grep -E '^[0-9]+\s' "$WORK/map/users.raw" > "$WORK/map/users.tsv"
```

`map/users.tsv` ist die Mapping-Tabelle `eventula_user_id → ULID → Handle →
created|existing`. **Sie wird gebraucht**, sobald Bestellungen migriert werden.

Der Lauf ist idempotent auf der E-Mail-Adresse: ein zweiter Durchlauf legt
nichts doppelt an und meldet `existing`. Bricht er mittendrin ab, einfach
wiederholen.

Erwartet in `users.err`:
`250 angelegt, 3 schon vorhanden, 141 mit Steam, 13 Platzhalter-Adressen, 15 Brand-Admins`
(die drei „schon vorhanden" sind der Probelauf aus 1.3; bei einem Lauf auf
einer leeren Instanz: `253 angelegt, 0 schon vorhanden, 143 mit Steam,
14 Platzhalter-Adressen, 17 Brand-Admins`).

### 1.4 Gegenprüfung

```bash
$KCTX exec konventix-db-1 -c postgres -- psql -U postgres -d konventix -c "
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

Dann feldweise gegen die Quelle vergleichen — Name, E-Mail, Passwort-Hash und
SteamID jedes einzelnen Kontos:

```bash
$KCTX exec konventix-db-1 -c postgres -- psql -U postgres -d konventix -At -F$'\t' -c "
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
  Die Begrüßungsmail an alle importierten Konten ist ein eigener,
  gewollter Schritt (#158, C18) und der Point of no return.
- **Keine Sitzungen, keine Tokens, keine Passwort-Resets.** Wer ein Passwort
  hatte, hat es weiter; der Rest kommt über Steam oder „Passwort vergessen".

## Schritt 2 — Events und Registrierungsarten

*(folgt)*

## Aufräumen

Nach jedem Lauf, auch nach einem abgebrochenen:

```bash
$KCTX exec $POD -c web -- rm -f /tmp/users.ndjson /tmp/apply-users.php
docker rm -f eventula-mig
rm -rf "$WORK"
```

Der Auszug enthält Klarnamen, Adressen und Passwort-Hashes. Er gehört weder
ins Repo noch in ein Ticket noch in einen Chat — nur `map/*.tsv` ist
aufzubewahren, und auch das nur bis der Cutover durch ist.
