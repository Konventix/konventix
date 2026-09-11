# Participant tickets and calendar files — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A participant can download a ticket PDF and add the event to their calendar, and an admission's scan code stops changing on every issue.

**Architecture:** Admission owns the ticket document and the token; Event owns the calendar file; Platform owns the watermark service; Messaging gains an inline attachment form. UI surfaces arrive as slot contributions, so neither Registration's nor Event's pages change. Nothing is written to disk: the ticket renders fresh per request and the calendar file is generated per request.

**Tech Stack:** PHP 8.5, Laravel, `barryvdh/laravel-dompdf` ^3.1 rendering Blade, `bacon/bacon-qr-code` ^3.0 as inline SVG data URIs, Vue 3 + Inertia v3, spatie/laravel-data, Pest.

**Spec:** `docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md` — the binding contract. Read it alongside your task.

**Issue:** #72. Every commit message ends in `(#72)`.

## Global Constraints

- **Branch `seating-2026-09-10`, merge request !48.** Never switch branches, never rebase, never push to any other ref.
- **Everything PHP runs in the container**: `vendor/bin/sail`. Host PHP is the wrong version and `DB_HOST=pgsql` resolves only in-network.
- **One test process at a time.** Every command that runs tests is wrapped: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact <path or --filter>`. The same lock wraps `vendor/bin/sail composer verify -- <modules>`. Never `composer test`, never the full suite.
- **No new composer or npm dependencies.** The calendar file is hand-rolled.
- **No cross-module Eloquent imports.** Events carry identifiers and scalars, never models (ADR-0004). Admission never queries a Resources or Registration table; Event never queries Messaging.
- **Every brand-owned model uses `BelongsToBrand`.** Console and job paths that run without a brand use `withoutBrandScope()` and set `brand_id` explicitly (ADR-0010, ADR-0029).
- **ULID primary keys, no cross-module foreign keys** (ADR-0014). Migrations are additive files; never edit a shipped one.
- **One Data object per form or endpoint**, with generated TypeScript. Never hand-write TS for a backend payload (ADR-0009).
- **`env('APP_ENV')` is forbidden.** The only production fact is `Konventix\Platform\Environment\DeploymentEnvironment::isProduction()` (ADR-0053).
- **All strings are English.** There is no `lang/` directory.
- **Frontend components reference semantic design tokens only**, never raw palette values (ADR-0008).
- **Business logic lives in Actions** with `DB::transaction` inside the Action. Controllers translate HTTP to Actions and back, and authorisation is the first line of a controller method.
- **A rendered PDF is asserted on its magic bytes and its length**, never byte-compared, following `BadgeDocumentTest` and `InvoiceDocumentTest`.
- **After any Data class change**: `flock <lock> vendor/bin/sail composer types:generate`, then `npx prettier --write resources/js/types`. The generated manifest must be prettier-clean or the pipeline's format job fails.
- **Before pushing**: `npx prettier --check resources/ "app-modules/*/resources/**/*.{ts,tsx,vue,js,css,json}"`.
- **Each task updates the `TODO.md` of every module it touched**, and the `SPEC.md` of any module whose public contract changed.
- **Commit messages are prose, one sentence, ending `(#72)`**, followed by the attribution lines your environment gives you. Stage only files you touched, by explicit path.
- **Deviations from the spec are appended to its §13**, never edited into its body.

---
# Platform section: Tasks 1-4 — two new Platform services

Binding contract: `docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md`,
especially §4.3 (watermarks), §6.1 (calendar format) and §13 (amendments).

Both services follow `Konventix\Platform\Media\MediaLibrary`'s shape: a
module reaches Platform the way every module already reaches `MediaLibrary`
— resolved from the container, never `new`'d with hidden state, never
reading `env()` or touching a cross-module Eloquent model (ADR-0004).

---

### Task 1: `DocumentWatermark::html()` — brand mark and forensic repeat

**Files:**
- Create: `app-modules/platform/src/Documents/DocumentWatermark.php`
- Test: `app-modules/platform/tests/DocumentWatermarkTest.php`

**Interfaces:**
- Consumes: nothing new. `$brandLogoDataUri` is expected to already be a
  resolved `data:` URI — the caller gets it from
  `Konventix\Platform\Media\MediaLibrary::dataUriFor(string $mediaId, ?string $variant = null): ?string`,
  the same way `BadgeDocumentRenderer` does; this class never touches
  `MediaLibrary` itself.
- Produces: `Konventix\Platform\Documents\DocumentWatermark::html(array $forensicLines, ?string $brandLogoDataUri, string $seed): string`,
  for Task 2 to extend on the same class, and for a later Admission task
  (outside this section) to call from the ticket view.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Platform\Documents\DocumentWatermark;

/**
 * Spec §4.3: the ticket's watermark layers, built so the badge (#23) and
 * the invoice can adopt them later — this service never knows what a
 * holder or an event is, only the strings and the data URI it is handed.
 */
it('renders the forensic lines at 12% opacity, deterministically from the seed', function (): void {
    $watermark = app(DocumentWatermark::class);
    $lines = ['Jane Doe', 'Konventix Summit 2026', 'Participant', 'ADM-01K5'];

    $first = $watermark->html($lines, null, 'admission-01K5EXAMPLE');
    $second = $watermark->html($lines, null, 'admission-01K5EXAMPLE');

    expect($first)->toBe($second)
        ->and($first)->toContain('Jane Doe')
        ->and($first)->toContain('opacity:0.12')
        ->and($first)->not->toContain('<img');
});

it('changes the tile arrangement when the seed changes, but keeps the forensic text', function (): void {
    $watermark = app(DocumentWatermark::class);
    $lines = ['Jane Doe', 'Konventix Summit 2026'];

    $a = $watermark->html($lines, null, 'admission-one');
    $b = $watermark->html($lines, null, 'admission-two');

    expect($a)->not->toBe($b)
        ->and($a)->toContain('Jane Doe')
        ->and($b)->toContain('Jane Doe');
});

it('renders the brand mark when a logo is given and omits it, without failing, when there is none', function (): void {
    $watermark = app(DocumentWatermark::class);

    $withLogo = $watermark->html(['Jane Doe'], 'data:image/png;base64,AAAA', 'admission-01K5EXAMPLE');
    $withoutLogo = $watermark->html(['Jane Doe'], null, 'admission-01K5EXAMPLE');

    expect($withLogo)->toContain('<img src="data:image/png;base64,AAAA"')
        ->and($withoutLogo)->not->toContain('<img');
});

it('escapes forensic text so a holder name cannot inject markup', function (): void {
    $watermark = app(DocumentWatermark::class);

    $html = $watermark->html(['<script>alert(1)</script>'], null, 'admission-xss');

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/DocumentWatermarkTest.php`
Expected: FAIL with `Error: Class "Konventix\Platform\Documents\DocumentWatermark" not found`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Platform\Documents;

/**
 * The watermark layers a ticket PDF needs (spec §4.3), built in Platform
 * rather than in Admission so the badge (#23) and the invoice can adopt the
 * same layers later — this service never knows what a holder, an event or
 * an admission is, only the strings and the data URI it is handed.
 *
 * Renders an HTML fragment for dompdf, not a full document: the consuming
 * view stacks this behind its own content. dompdf's CSS support is a
 * fraction of a browser's, so every layer here sticks to what it actually
 * renders correctly — absolute positioning, `transform: rotate`, opacity
 * and inline styles — and never flexbox, grid, or an external asset.
 * dompdf ships with remote fetching disabled, so a logo arrives here
 * already resolved to a `data:` URI by the caller (via
 * `MediaLibrary::dataUriFor()`), the same way `BadgeDocumentRenderer` and
 * `InvoiceDocumentRenderer` inline every image today.
 *
 * **No clear rectangle around the QR is computed here.** Spec §4.3 asks for
 * a clear zone so the forensic repeat never degrades a scan; that is a
 * document-composition concern, not a watermark one — only the consuming
 * view knows where the QR sits on its page. The view achieves it by
 * placing the QR's own container *after* this fragment's markup in the
 * page HTML and giving it an opaque background: dompdf paints absolutely
 * positioned boxes with no `z-index` in document order, the same as a
 * browser, so a later opaque box simply covers whatever this layer drew
 * underneath it. Getting that stacking order wrong is the ticket view's
 * bug to avoid, not this class's to prevent.
 */
class DocumentWatermark
{
    /**
     * A 4-column by 6-row grid is dense enough that no reasonably sized
     * forensic phrase list repeats in a way that looks empty, and sparse
     * enough that the page underneath stays legible through it.
     */
    private const int GRID_COLUMNS = 4;

    private const int GRID_ROWS = 6;

    /**
     * One fixed angle for every tile, not a per-tile random one: spec §4.3
     * calls for the repeat to run "diagonally across the page" — a single
     * consistent diagonal, the way a printed security pattern does, not a
     * scatter of angles.
     */
    private const float ROTATION_DEGREES = -28.0;

    /**
     * How far a tile may drift off its grid cell, in percentage points of
     * the page — enough that the rows do not read as a mechanical grid,
     * small enough that the text stays roughly where a forensic reader
     * expects it.
     */
    private const float JITTER_PERCENT = 6.0;

    /**
     * `$forensicLines` is a list of already-composed strings — this class
     * never knows a holder's name from an admission id, it only lays out
     * whatever strings it is handed. `$seed` drives every position and
     * rotation offset so that two renders of the same ticket (same seed,
     * same lines) are byte-identical; a re-download must never look like a
     * different, possibly forged, document.
     */
    public function html(array $forensicLines, ?string $brandLogoDataUri, string $seed): string
    {
        return $this->brandMarkLayer($brandLogoDataUri).$this->forensicLayer($forensicLines, $seed);
    }

    private function brandMarkLayer(?string $brandLogoDataUri): string
    {
        if ($brandLogoDataUri === null) {
            // The brand having no logo must still render a page (spec
            // §4.3: "Absent when the brand has no logo; the page must
            // still render"), so this layer degrades to nothing.
            return '';
        }

        return sprintf(
            '<div style="position:absolute; left:15%%; top:32%%; width:70%%; opacity:0.06; text-align:center;">'
            .'<img src="%s" style="width:100%%;" alt=""></div>',
            e($brandLogoDataUri),
        );
    }

    private function forensicLayer(array $forensicLines, string $seed): string
    {
        if ($forensicLines === []) {
            return '';
        }

        $tiles = '';
        $lineCount = count($forensicLines);

        for ($row = 0; $row < self::GRID_ROWS; $row++) {
            for ($column = 0; $column < self::GRID_COLUMNS; $column++) {
                $index = $row * self::GRID_COLUMNS + $column;
                $text = $forensicLines[$index % $lineCount];

                $left = $column * (100 / self::GRID_COLUMNS) + $this->seededJitter($seed, $index, 'x');
                $top = $row * (100 / self::GRID_ROWS) + $this->seededJitter($seed, $index, 'y');

                $tiles .= sprintf(
                    '<div style="position:absolute; left:%.2f%%; top:%.2f%%; width:60%%; '
                    .'opacity:0.12; transform:rotate(%.1fdeg); font-size:9pt; '
                    .'white-space:nowrap; color:#000000;">%s</div>',
                    $left,
                    $top,
                    self::ROTATION_DEGREES,
                    e($text),
                );
            }
        }

        return $tiles;
    }

    /**
     * A pure function of `$seed`, the tile index and the axis — never
     * `mt_rand()`/`random_int()`. This server is a long-running Octane
     * worker (CLAUDE.md): a source of randomness that touches PHP's
     * process-global PRNG state would perturb whatever the next request on
     * the same worker draws from it, for a watermark that has no need to
     * ever be unpredictable in the first place.
     */
    private function seededJitter(string $seed, int $index, string $axis): float
    {
        $fraction = crc32($seed.'|'.$axis.'|'.$index) / 4294967295;

        return ($fraction - 0.5) * self::JITTER_PERCENT;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/DocumentWatermarkTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- platform`

```bash
git add app-modules/platform/src/Documents/DocumentWatermark.php app-modules/platform/tests/DocumentWatermarkTest.php
git commit -m "Add DocumentWatermark::html(), the ticket PDF's seeded brand-mark and forensic-repeat watermark layers (#72)."
```

---

### Task 2: `DocumentWatermark::testSystemBanner()` — the off-production band

**Files:**
- Modify: `app-modules/platform/src/Documents/DocumentWatermark.php`
- Test: `app-modules/platform/tests/DocumentWatermarkTest.php`

**Interfaces:**
- Consumes: `Konventix\Platform\Environment\DeploymentEnvironment::isProduction(): bool`
  (ADR-0053, already bound as a singleton in `PlatformServiceProvider`).
- Produces: `Konventix\Platform\Documents\DocumentWatermark::testSystemBanner(): string`,
  for the same later ticket-view task that calls `html()`.

- [ ] **Step 1: Write the failing test**

```php
it('bands TESTSYSTEM off production, driven through DeploymentEnvironment', function (): void {
    config(['app.env' => 'staging']);

    $watermark = app(DocumentWatermark::class);

    expect($watermark->testSystemBanner())->toContain('TESTSYSTEM');
});

it('renders no TESTSYSTEM band on production', function (): void {
    config(['app.env' => 'production']);

    $watermark = app(DocumentWatermark::class);

    expect($watermark->testSystemBanner())->toBe('');
});
```

Append both `it()`s to `app-modules/platform/tests/DocumentWatermarkTest.php`, after the four from Task 1.

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/DocumentWatermarkTest.php`
Expected: FAIL with `Error: Call to undefined method Konventix\Platform\Documents\DocumentWatermark::testSystemBanner()`.

- [ ] **Step 3: Implement**

Add the import, turn the class into a constructor-injected service, and add the new method — `DocumentWatermark.php` becomes:

```php
namespace Konventix\Platform\Documents;

use Konventix\Platform\Environment\DeploymentEnvironment;

// ...same class docblock as Task 1...
class DocumentWatermark
{
    // ...GRID_COLUMNS / GRID_ROWS / ROTATION_DEGREES / JITTER_PERCENT stay unchanged...

    public function __construct(private readonly DeploymentEnvironment $environment) {}

    // ...html(), brandMarkLayer(), forensicLayer(), seededJitter() stay unchanged...

    /**
     * Spec §4.3, layer 3: a single diagonal band reading TESTSYSTEM, drawn
     * on every instance that is not literally production (ADR-0053) — this
     * is the renderer ADR-0053 §5 says would ask `DeploymentEnvironment`,
     * and the ticket is its first caller. Never `env('APP_ENV')` here or
     * anywhere else that needs this fact (CLAUDE_extra.md).
     */
    public function testSystemBanner(): string
    {
        if ($this->environment->isProduction()) {
            return '';
        }

        return '<div style="position:absolute; left:-15%; top:44%; width:130%; '
            .'transform:rotate(-28deg); opacity:0.22; background:#dc2626; '
            .'color:#ffffff; text-align:center; font-size:22pt; font-weight:bold; '
            .'padding:6pt 0;">TESTSYSTEM &middot; TESTSYSTEM &middot; TESTSYSTEM</div>';
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/DocumentWatermarkTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- platform`

```bash
git add app-modules/platform/src/Documents/DocumentWatermark.php app-modules/platform/tests/DocumentWatermarkTest.php
git commit -m "Add DocumentWatermark::testSystemBanner(), the off-production TESTSYSTEM band driven through DeploymentEnvironment (#72)."
```

---

### Task 3: `CalendarFile::forEvent()` — VCALENDAR/VEVENT structure and the fold/escape encoder

**Files:**
- Create: `app-modules/platform/src/Calendar/CalendarFile.php`
- Test: `app-modules/platform/tests/CalendarFileTest.php`

**Interfaces:**
- Consumes: nothing new — only `DateTimeInterface` from PHP core and
  Laravel's global `now()` helper (`Illuminate\Support\Carbon::now()`, so
  `Carbon::setTestNow()` controls it in tests).
- Produces: `Konventix\Platform\Calendar\CalendarFile::forEvent(string $uid, string $summary, ?string $description, ?string $location, ?string $url, DateTimeInterface $startsAt, DateTimeInterface $endsAt, ?DateTimeInterface $lastModifiedAt = null): string`.
  Structurally complete after this task; Task 4 hardens the UTC conversion
  inside it. Later Event, Registration and Admission tasks (outside this
  section, per spec §13's amendment) call this same signature for the
  public `calendar.ics` route and the confirmation-mail attachment.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Platform\Calendar\CalendarFile;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Spec §6.1: one VCALENDAR holding one VEVENT — an event has no
 * sub-sessions, so there is never a second one — and no SEQUENCE: the
 * repository has no counter to derive one from, LAST-MODIFIED is what
 * clients use instead.
 */
it('emits a minimal event as CRLF-terminated ICS with no optional properties', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $ics = CalendarFile::forEvent(
        uid: 'event-01K5EXAMPLE@brand.konventix.test',
        summary: 'Konventix Summit',
        description: null,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    expect($ics)->toBe(implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Konventix//Calendar 1.0//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:event-01K5EXAMPLE@brand.konventix.test',
        'DTSTAMP:20260911T090000Z',
        'DTSTART:20261001T080000Z',
        'DTEND:20261001T170000Z',
        'SUMMARY:Konventix Summit',
        'END:VEVENT',
        'END:VCALENDAR',
    ])."\r\n")
        ->not->toContain('DESCRIPTION:')
        ->not->toContain('LOCATION:')
        ->not->toContain('URL:')
        ->not->toContain('LAST-MODIFIED:')
        ->not->toContain('SEQUENCE:');
});

it('folds a long content line at 75 octets with a single leading space on the continuation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $summary = str_repeat('A', 100);

    $ics = CalendarFile::forEvent(
        uid: 'event-fold@brand.konventix.test',
        summary: $summary,
        description: null,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    // "SUMMARY:" is 8 octets; the first physical line carries exactly 75
    // octets (67 of the 100 A's), the continuation carries the rest behind
    // a single leading space.
    $expectedLine = 'SUMMARY:'.str_repeat('A', 67)."\r\n ".str_repeat('A', 33);

    expect($ics)->toContain($expectedLine);

    foreach (explode("\r\n", $ics) as $physicalLine) {
        expect(strlen($physicalLine))->toBeLessThanOrEqual(75);
    }
});

it('never splits a multi-byte character across a fold, even when the naive octet cut would land inside it', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    // "DESCRIPTION:" is 12 octets; 62 further ASCII octets land the naive
    // 75-octet cut on the first byte of the two-byte 'ü' (0xC3 0xBC) that
    // follows — exactly the case the octet-counting guard exists for.
    $description = str_repeat('A', 62).'ü'.str_repeat('B', 5);

    $ics = CalendarFile::forEvent(
        uid: 'event-multibyte@brand.konventix.test',
        summary: 'Multi-byte fold',
        description: $description,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    $expectedLine = 'DESCRIPTION:'.str_repeat('A', 62)."\r\n ".'ü'.str_repeat('B', 5);

    expect($ics)->toContain($expectedLine)
        ->and(mb_check_encoding($ics, 'UTF-8'))->toBeTrue();
});

it('escapes backslash, semicolon, comma and newlines inside a TEXT value, in that order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    // Raw: one backslash, one semicolon, one comma, one real newline.
    $description = 'Tickets'.chr(92).'Admission;VIP,Row 1'."\n".'Gate A';

    $ics = CalendarFile::forEvent(
        uid: 'event-escape@brand.konventix.test',
        summary: 'Escaping',
        description: $description,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    // Expected, in escaping order: \ -> \\, ; -> \;, , -> \,, newline -> \n
    // (a literal backslash followed by the letter n).
    $expected = 'Tickets'.chr(92).chr(92).'Admission'.chr(92).';VIP'.chr(92).',Row 1'.chr(92).'nGate A';

    expect(explode("\r\n", $ics))->toContain('DESCRIPTION:'.$expected);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/CalendarFileTest.php`
Expected: FAIL with `Error: Class "Konventix\Platform\Calendar\CalendarFile" not found`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Platform\Calendar;

use DateTimeInterface;

/**
 * A hand-rolled RFC 5545 encoder (spec §6.1), not a package: one VCALENDAR
 * holding one VEVENT is the entire surface this needs, and pulling in a
 * calendar library for three constant lines and a fold algorithm would be
 * a dependency for nothing.
 *
 * Every module reaches this the way they already reach `MediaLibrary`
 * (2026-09-11 planning amendment, spec §13): it takes scalars and returns
 * bytes, so Registration can attach a calendar file to the confirmation
 * mail without importing Event (ADR-0004) — Event, Registration and
 * Admission each keep their own read model of a venue's name and an
 * event's time, and hand the resolved strings and instants in here.
 *
 * `$startsAt`/`$endsAt`/`$lastModifiedAt` are emitted as UTC instants
 * (`…Z`), never with a `TZID`, so there is no `VTIMEZONE` block. A correct
 * `VTIMEZONE` needs full daylight-saving transition rules for the zone; a
 * UTC instant needs none of that and every client renders it correctly in
 * its own zone regardless of where it runs. The event itself still stores
 * wall clock plus an IANA zone (ADR-0027) — converting to UTC is this
 * method's job, not the caller's.
 *
 * No `SEQUENCE`: `LAST-MODIFIED` is the property clients use for that, and
 * the repository has no revision counter to derive a sequence number from.
 */
final class CalendarFile
{
    private function __construct() {}

    /**
     * `$uid` is composed by the caller (the event id plus the brand's
     * host) and passed in whole — stability of the identifier for
     * re-importing calendar clients is the caller's contract, not this
     * encoder's concern.
     *
     * `$description`, `$location`, `$url` and `$lastModifiedAt` are
     * omitted entirely when null, never emitted as an empty property — an
     * event with no venue (spec §11) still produces a valid file this way.
     */
    public static function forEvent(
        string $uid,
        string $summary,
        ?string $description,
        ?string $location,
        ?string $url,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?DateTimeInterface $lastModifiedAt = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Konventix//Calendar 1.0//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            self::property('UID', self::escapeText($uid)),
            self::property('DTSTAMP', self::utcStamp(now())),
            self::property('DTSTART', self::utcStamp($startsAt)),
            self::property('DTEND', self::utcStamp($endsAt)),
            self::property('SUMMARY', self::escapeText($summary)),
        ];

        if ($description !== null) {
            $lines[] = self::property('DESCRIPTION', self::escapeText($description));
        }

        if ($location !== null) {
            $lines[] = self::property('LOCATION', self::escapeText($location));
        }

        if ($url !== null) {
            $lines[] = self::property('URL', $url);
        }

        if ($lastModifiedAt !== null) {
            $lines[] = self::property('LAST-MODIFIED', self::utcStamp($lastModifiedAt));
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::foldLine(...), $lines))."\r\n";
    }

    private static function property(string $name, string $value): string
    {
        return $name.':'.$value;
    }

    /**
     * Provisional: this trusts `$moment`'s own wall clock and appends `Z`
     * literally, which is only correct when `$moment` is already UTC. Task
     * 4 replaces this with a real UTC conversion once a failing test for a
     * non-UTC wall clock proves the gap.
     */
    private static function utcStamp(DateTimeInterface $moment): string
    {
        return $moment->format('Ymd\THis\Z');
    }

    /**
     * `\`, `;`, `,` and newlines escaped in that order (RFC 5545 §3.3.11)
     * — backslash first, because every escape this method adds afterwards
     * is itself a backslash sequence, and escaping backslash last would
     * double-escape them.
     */
    private static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * RFC 5545 §3.1 line folding: no physical line exceeds 75 octets
     * (a continuation line's single leading space counts inside that
     * budget), and the split point counts octets, never characters, so a
     * multi-byte UTF-8 character is never divided between two lines — a
     * continuation byte (`10xxxxxx`) sitting right at the cut point pushes
     * the boundary back until it lands on a character start.
     */
    private static function foldLine(string $line): string
    {
        $octets = strlen($line);

        if ($octets <= 75) {
            return $line;
        }

        $folded = '';
        $offset = 0;
        $limit = 75;

        while ($offset < $octets) {
            $end = min($offset + $limit, $octets);

            while ($end < $octets && (ord($line[$end]) & 0xC0) === 0x80) {
                $end--;
            }

            $folded .= ($offset === 0 ? '' : "\r\n ").substr($line, $offset, $end - $offset);
            $offset = $end;
            $limit = 74;
        }

        return $folded;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/CalendarFileTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- platform`

```bash
git add app-modules/platform/src/Calendar/CalendarFile.php app-modules/platform/tests/CalendarFileTest.php
git commit -m "Add Konventix\Platform\Calendar\CalendarFile::forEvent(), a hand-rolled RFC 5545 encoder with CRLF fold/escape handling (#72)."
```

---

### Task 4: `CalendarFile` — true UTC conversion, UID stability and determinism

**Files:**
- Modify: `app-modules/platform/src/Calendar/CalendarFile.php`
- Test: `app-modules/platform/tests/CalendarFileTest.php`

**Interfaces:**
- Consumes: the same `forEvent()` built in Task 3 — this task hardens its
  internals, the public signature does not change.
- Produces: `Konventix\Platform\Calendar\CalendarFile::forEvent(string $uid, string $summary, ?string $description, ?string $location, ?string $url, DateTimeInterface $startsAt, DateTimeInterface $endsAt, ?DateTimeInterface $lastModifiedAt = null): string`,
  now spec-correct for a non-UTC wall clock, which is the shape Event's
  `GET /events/{event:slug}/calendar.ics` route and Registration's
  confirmation-mail attachment (outside this section, per spec §13) both
  call.

- [ ] **Step 1: Write the failing test**

```php
it('converts DTSTART and DTEND to UTC instants for an event stored on a non-UTC wall clock', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    // 14:00 in Europe/Berlin on 2026-10-01 is CEST (UTC+2) — 12:00 UTC.
    $startsAt = Carbon::parse('2026-10-01 14:00:00', 'Europe/Berlin');
    $endsAt = Carbon::parse('2026-10-01 18:00:00', 'Europe/Berlin');

    $ics = CalendarFile::forEvent(
        uid: 'event-tz@brand.konventix.test',
        summary: 'Timezone check',
        description: null,
        location: null,
        url: null,
        startsAt: $startsAt,
        endsAt: $endsAt,
    );

    expect(explode("\r\n", $ics))
        ->toContain('DTSTART:20261001T120000Z')
        ->toContain('DTEND:20261001T160000Z');
});

it('gives byte-identical output for the same input, including a stable UID, across two calls', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $arguments = [
        'uid' => 'event-01K5STABLE@brand.konventix.test',
        'summary' => 'Konventix Summit',
        'description' => 'Doors at nine.',
        'location' => 'Halle 3, Messeplatz 1, 50679 Köln',
        'url' => 'https://brand.konventix.test/events/summit',
        'startsAt' => new DateTimeImmutable('2026-10-01T08:00:00Z'),
        'endsAt' => new DateTimeImmutable('2026-10-01T17:00:00Z'),
        'lastModifiedAt' => new DateTimeImmutable('2026-09-01T12:00:00Z'),
    ];

    $first = CalendarFile::forEvent(...$arguments);
    $second = CalendarFile::forEvent(...$arguments);

    expect($first)->toBe($second)
        ->and($first)->not->toContain('SEQUENCE:');

    $lines = explode("\r\n", $first);

    expect($lines)
        ->toContain('UID:event-01K5STABLE@brand.konventix.test')
        ->toContain('LOCATION:Halle 3\, Messeplatz 1\, 50679 Köln')
        ->toContain('URL:https://brand.konventix.test/events/summit')
        ->toContain('LAST-MODIFIED:20260901T120000Z');
});

it('omits DESCRIPTION, LOCATION, URL and LAST-MODIFIED when none are given, and still emits a valid file', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $ics = CalendarFile::forEvent(
        uid: 'event-no-venue@brand.konventix.test',
        summary: 'No venue yet',
        description: null,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and(rtrim($ics, "\r\n"))->toEndWith("END:VEVENT\r\nEND:VCALENDAR")
        ->and($ics)
            ->not->toContain('DESCRIPTION:')
            ->not->toContain('LOCATION:')
            ->not->toContain('URL:')
            ->not->toContain('LAST-MODIFIED:');
});
```

Append all three `it()`s to `app-modules/platform/tests/CalendarFileTest.php`, after the four from Task 3.

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/CalendarFileTest.php`
Expected: FAIL on the timezone test — `Failed asserting that an array contains 'DTSTART:20261001T120000Z'.`, the array instead holding `'DTSTART:20261001T140000Z'`: Task 3's `utcStamp()` printed the Berlin wall clock and tacked a literal `Z` onto it instead of converting.

- [ ] **Step 3: Implement**

Replace Task 3's `utcStamp()` with a real conversion, driven by the instant's Unix timestamp rather than by `$moment`'s own attached timezone:

```php
    /**
     * The UTC instant for any wall clock, converted via the Unix
     * timestamp rather than a timezone swap on `$moment` — a
     * `DateTimeInterface` carries no guarantee of being mutable, and
     * reading the absolute instant sidesteps that regardless of which zone
     * the caller's clock is attached to. This is the UTC conversion spec
     * §6.1 asks for: `$moment` may be a Carbon instance on the event's own
     * IANA zone (ADR-0027), and the wire format is always `…Z`, never a
     * `TZID`.
     */
    private static function utcStamp(DateTimeInterface $moment): string
    {
        return gmdate('Ymd\THis\Z', $moment->getTimestamp());
    }
```

This replaces the single `utcStamp()` method from Task 3's `CalendarFile.php`; every other method (`forEvent()`, `property()`, `escapeText()`, `foldLine()`) is unchanged.

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/platform/tests/CalendarFileTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- platform`

```bash
git add app-modules/platform/src/Calendar/CalendarFile.php app-modules/platform/tests/CalendarFileTest.php
git commit -m "Convert CalendarFile's DTSTART/DTEND/DTSTAMP/LAST-MODIFIED to true UTC instants and cover UID stability and determinism (#72)."
```
# Admission token section: Tasks 5-10 — a stable ticket code

Binding contract: `docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md`,
especially §2.1 (the gap), §5 (a stable ticket code), §9 (observability) and
§11 (testing).

§2.1: `IssueAdmissionToken::handle()` mints a fresh, random nonce on every
call, and its docblock — along with `AdmissionTokenPayload`'s and
`IssueBadge`'s — claims this strands the previously printed QR. It does not:
no nonce is stored on `admissions`, neither `VerifyAdmissionToken` nor
`RecordScan` compares one, and `CheckInRejectionReason::NonceSuperseded`
exists but is referenced nowhere. Tasks 5-10 close that gap: an admission's
token becomes stable across repeated reads and a superseded one is refused
at the door, online and offline.

The nonce becomes **deterministic** (TKT-F-018, ported as is — not random):
`admissionId . '.' . token_issued_at` (whole-second Unix timestamp). This is
the load-bearing decision the rest of this section rests on, so it is
recorded once, here, rather than re-derived in every task below. The spec
says the plaintext nonce is never stored and that `current()` must return
the *same* token body on a second call — those two constraints are
irreconcilable with a random nonce, because nothing would be left to
reconstruct it from. A deterministic nonce needs no storage: `current()`
regenerates it from the admission's own id (already known) and its stored
`token_issued_at` (Task 5), which is all `rotate()` ever changes. The nonce
itself does not need to be a secret — a device or an attacker who knows an
admission's id and issue time still cannot mint a valid token without the
Ed25519 private key, which is what a token is actually trusted on
(`AdmissionTokenCodec`). `token_nonce_hash` is HMAC-SHA256 *of* that nonce,
keyed with the pepper, exactly as §5 specifies; it is what makes the nonce
non-reversible from a database read even though it is not itself random.

`token_issued_at`'s column keeps Laravel's default second precision (no
explicit precision argument, matching every other timestamp column in this
schema) deliberately: Eloquent's own date-write format
(`Illuminate\Database\Eloquent\Concerns\HasAttributes::getDateFormat()`) is
`'Y-m-d H:i:s'` regardless of the column's precision, so a nonce derived
from anything finer than whole seconds would desync the very first time the
admission is reloaded from the database in a later request. The nonce
derivation uses `Carbon::getTimestamp()` (an `int`) for exactly this reason
— it agrees with itself before and after a database round trip by
construction, not by care taken at each call site.

---

### Task 5: The columns — `admissions` and `entrance_delta_entries`, plus the pepper

**Files:**
- Create: `app-modules/admission/database/migrations/2026_09_11_100000_add_token_stability_columns_to_admissions_table.php`
- Create: `app-modules/admission/database/migrations/2026_09_11_100100_add_payload_to_entrance_delta_entries_table.php`
- Create: `config/admission.php`
- Modify: `app-modules/admission/src/Models/Admission.php` (lines 27-40 `@property` block, lines 63-70 `casts()`)
- Modify: `app-modules/admission/src/Models/EntranceDeltaEntry.php` (lines 24-30 docblock, lines 35-37 constants, lines 54-58 `$fillable`; adds a `casts()` method)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php` (new file — every task in this section appends to it)

**Interfaces:**
- Consumes: nothing new. `config('admission.token_pepper')` did not exist
  before this task; `config('app.key')` is read only indirectly, through
  `env('APP_KEY')` inside the new config file (see Step 3 for why not
  `config('app.key')`).
- Produces: `admissions.token_nonce_hash: ?string` and
  `admissions.token_issued_at: ?Carbon` (via `Admission::casts()`);
  `entrance_delta_entries.payload: ?array` (via `EntranceDeltaEntry::casts()`);
  `EntranceDeltaEntry::KIND_TOKEN_SUPERSEDED = 'token_superseded'`;
  `config('admission.token_pepper'): string`. Task 6 writes the first three
  columns and reads the pepper; Task 7 reads `token_nonce_hash` and the
  pepper; Task 8 reads and serializes `payload`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Konventix\Admission\Models\Admission;

/**
 * §5: a stable ticket code. This file grows one `it()` per task in this
 * plan section (Tasks 5-10) rather than one file per task, because every
 * later case exercises the same `IssueAdmissionToken`/`VerifyAdmissionToken`
 * /`RecordScan` triangle Task 6 and 7 stand up.
 */
it('gives admissions nullable token-stability columns and entrance_delta_entries a payload column, with a pepper needing no new env var', function (): void {
    expect(Schema::hasColumns('admissions', ['token_nonce_hash', 'token_issued_at']))->toBeTrue()
        ->and(Schema::hasColumn('entrance_delta_entries', 'payload'))->toBeTrue();

    $this->withBrand();
    $admission = Admission::factory()->create();

    expect($admission->token_nonce_hash)->toBeNull()
        ->and($admission->token_issued_at)->toBeNull();

    expect(config('admission.token_pepper'))->toBeString()
        ->and(config('admission.token_pepper'))->not->toBe('');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL — `Failed asserting that false is true.` on the first
`Schema::hasColumns('admissions', ...)` assertion: neither column exists
yet, and `config/admission.php` does not exist either, so the chain never
reaches the pepper assertions.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stable ticket code (design doc §5, TKT-F-018 ported as is). Both
     * columns are nullable and stay null for every admission that exists
     * before this migration runs — `VerifyAdmissionToken::isSuperseded()`
     * (Task 7) treats a null `token_nonce_hash` as "accept anything",
     * which is what keeps every token already in circulation working
     * across the deploy with no backfill (`IssueAdmissionToken`'s own
     * docblock, Task 9, says why one is not run).
     *
     * `token_nonce_hash` is HMAC-SHA256 of the token's nonce, keyed with
     * `config('admission.token_pepper')`; the plaintext nonce itself is
     * never persisted anywhere. `token_issued_at` is both a support-
     * question timestamp and, since the nonce is deterministic rather than
     * random, the one piece of state `IssueAdmissionToken::current()`
     * needs to regenerate the exact same nonce on a later call without
     * ever having stored it. Deliberately left at the schema's default
     * second precision — see this file's own section header for why a
     * finer one would not survive a database round trip anyway.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->string('token_nonce_hash', 64)->nullable()->after('state');
            $table->timestamp('token_issued_at')->nullable()->after('token_nonce_hash');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn(['token_nonce_hash', 'token_issued_at']);
        });
    }
};
```

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third delta kind needs a payload the first two never did:
     * 'revoked' and 'checked_in' (`EntranceDeltaEntry`) are both fully
     * described by the row's own `admission_id` and `kind`, but
     * 'token_superseded' (design doc §5, Task 6) also has to carry the new
     * `token_nonce_hash`, or a device has nothing to compare a scanned
     * token's nonce against (Task 8). Nullable JSON because the other two
     * kinds still carry none — this is additive, not a shape every row
     * must now satisfy.
     */
    public function up(): void
    {
        Schema::table('entrance_delta_entries', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('entrance_delta_entries', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
```

```php
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admission Token Nonce Pepper
    |--------------------------------------------------------------------------
    |
    | Design doc 2026-09-11 §5 (TKT-F-018, ported as is): admissions.
    | token_nonce_hash is HMAC-SHA256 of the token's nonce, keyed with this
    | value. The plaintext nonce is never persisted — only this hash of it.
    |
    | Defaults to a value *derived* from APP_KEY, read directly with env()
    | rather than config('app.key'): config files are each require()'d
    | independently while Illuminate\Foundation\Bootstrap\LoadConfiguration
    | is still filling the repository, 'admission' sorts ahead of 'app' in
    | that pass, and reading config('app.key') from inside this file is
    | therefore not guaranteed to see it set yet. Every deployment already
    | has an APP_KEY (ADR-0028: it already encrypts brand mail credentials
    | and PSP keys), so upgrading to this feature needs no new secret and
    | no coordinated rollout — the admission module has no config file to
    | follow the shape of, so this one is placed the way every other
    | module-named config lives at the host's config/ root
    | (config/commerce.php), read via config('admission.*'), never env()
    | outside this file.
    |
    | This ties the pepper to APP_KEY's own rotation (ADR-0049 §12): moving
    | APP_KEY to APP_PREVIOUS_KEYS and deploying a new one changes this
    | default too, and every admission's stored token_nonce_hash stops
    | matching what VerifyAdmissionToken recomputes — every token already
    | in circulation would read as superseded. An operator planning an
    | APP_KEY rotation while tickets are live should set
    | ADMISSION_TOKEN_PEPPER explicitly first, decoupling the two.
    |
    */

    'token_pepper' => env('ADMISSION_TOKEN_PEPPER') ?: hash_hmac('sha256', 'konventix.admission.token-nonce-pepper', (string) env('APP_KEY')),

];
```

`Admission.php` — add two `@property` lines and one cast (`token_nonce_hash`
needs no cast; it is read and written as a plain string):

```php
 * @property string|null $registration_id
 * @property string|null $amendment_id
 * @property string|null $assignment_id
 * @property string $event_id
 * @property string|null $holder_user_id
 * @property string $role
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property AdmissionState $state
 * @property string|null $token_nonce_hash
 * @property Carbon|null $token_issued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
```

```php
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'state' => AdmissionState::class,
            'token_issued_at' => 'datetime',
        ];
    }
```

Deliberately not added to `$fillable`: like `brand_id`, these two are
system-managed, written only by `IssueAdmissionToken::rotate()` through
`forceFill()` (ADR-0010's own pattern for exactly this), never by a
controller's mass assignment.

`EntranceDeltaEntry.php` — extend the class docblock, add the third kind
constant, and add `payload` to `$fillable` plus a `casts()` method (the
model has none today):

```php
/**
 * One entry in the PII-free delta feed (ADR-0033): an admission ID that a
 * device must now treat as revoked, as already checked in, or — since
 * design doc §5 (Task 6) — as holding a superseded token.
 *
 * Positive decisions come from the signature; these are the negative ones a
 * signature cannot make. `revoked` and `checked_in` carry nothing but the
 * admission ID; `token_superseded` carries `payload = ['hash' => ...]`, the
 * admission's new `token_nonce_hash`, because a device cannot decide "is
 * the token I just scanned the current one" from an opaque ID alone — it
 * needs the hash to compare a scanned token's nonce against. That payload
 * is still PII-free: a hash of a nonce says nothing about a person.
 *
 * Append-only, keyed by a monotonic `sequence` the client walks. Not a ULID
 * primary key, unlike every other model here (ADR-0014): this is an ordering
 * a client resumes from, not an identity anything references.
 *
 * @property int $sequence
 * @property string $brand_id
 * @property string $event_id
 * @property string $admission_id
 * @property string $kind
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 */
class EntranceDeltaEntry extends Model
{
    use BelongsToBrand;

    public const string KIND_REVOKED = 'revoked';

    public const string KIND_CHECKED_IN = 'checked_in';

    public const string KIND_TOKEN_SUPERSEDED = 'token_superseded';

    protected $primaryKey = 'sequence';

    /**
     * The one place an auto-incrementing key is correct in this codebase —
     * see the class docblock.
     */
    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * Append-only: there is no `updated_at` to maintain.
     */
    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'admission_id',
        'kind',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

`config/admission.php` is a new root `config/*` file, so
`contrib/verify-plan.sh`'s automatic mapping would mark the full suite
owed; the `-- admission` override is a deliberate, named exception
(CLAUDE.md: "Name modules to override the mapping"), reasonable here
because the only behaviour this task adds is two nullable, unused-until-
Task-6 columns and one additive default-valued config key. The full suite
is still the pipeline's before merge.

```bash
git add app-modules/admission/database/migrations/2026_09_11_100000_add_token_stability_columns_to_admissions_table.php app-modules/admission/database/migrations/2026_09_11_100100_add_payload_to_entrance_delta_entries_table.php config/admission.php app-modules/admission/src/Models/Admission.php app-modules/admission/src/Models/EntranceDeltaEntry.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Add admissions token-stability columns, an entrance_delta_entries payload column, and the admission token pepper config (#72)."
```

---

### Task 6: The split — `IssueAdmissionToken` gains `current()`/`rotate()`, loses `handle()`

**Files:**
- Modify: `app-modules/admission/src/Actions/IssueAdmissionToken.php` (full
  rewrite of the 82-line file)
- Modify: `app-modules/admission/src/Actions/IssueBadge.php` (line 94, plus
  lines 24-27 docblock)
- Modify: `app-modules/admission/tests/EntranceDayAndReentryTest.php`
  (lines 75, 86, 101, 118, 131, 151, 169, 180 — every `->handle(` becomes
  `->current(`)
- Modify: `app-modules/admission/tests/AdmissionTokenTest.php` (lines 186,
  196, 208 — same rename)
- Modify: `app-modules/admission/tests/EntranceApiTest.php` (lines 112, 147,
  176, 222 — same rename)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
  (append)

`IssueBadge.php` and the three existing test files are not exclusively
owned by this section, but `handle()`'s removal breaks every one of them,
and the task is explicit that every caller — including tests — moves in
the same commit. No other plan section touches `IssueAdmissionToken`,
so there is no ownership conflict.

**Interfaces:**
- Consumes: `admissions.token_nonce_hash`/`token_issued_at` and
  `config('admission.token_pepper')` (Task 5); `EntranceDeltaEntry::
  KIND_TOKEN_SUPERSEDED` and its `payload` column (Task 5);
  `AdmissionTokenCodec::encode(AdmissionTokenPayload, string $kid, string
  $privateKeyBase64): string` and `AdmissionTokenPayload`'s constructor
  (unchanged, both outside this section's ownership).
- Produces: `IssueAdmissionToken::current(Admission $admission, array
  $dayIds = []): string` and `IssueAdmissionToken::rotate(Admission
  $admission, array $dayIds = []): string`. Task 7 calls both in its test
  to mint a stale token and then supersede it; Task 8 calls `rotate()` to
  produce a delta entry; Task 9's docblock test reads this file's source;
  Task 10 calls both to produce a rejection. Outside this section, the
  ticket-renderer task (§4.4: "rendered fresh on every request") and the
  badge editor both call `current()` as their read path — this task wires
  `IssueBadge` to it as the one caller already in the codebase.

- [ ] **Step 1: Write the failing test**

```php
it('returns the same token body from two current() calls, and a different one after rotate()', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create();
    $issuer = app(IssueAdmissionToken::class);

    $first = $issuer->current($admission);
    $second = $issuer->current($admission->fresh());

    expect($second)->toBe($first);

    $this->travel(1)->second();
    $rotated = $issuer->rotate($admission->fresh());

    expect($rotated)->not->toBe($first);
});

it('writes a token_superseded delta entry carrying the new hash on rotate()', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create();
    app(IssueAdmissionToken::class)->rotate($admission);

    $admission->refresh();

    $entry = EntranceDeltaEntry::withoutBrandScope()
        ->where('admission_id', $admission->id)
        ->where('kind', EntranceDeltaEntry::KIND_TOKEN_SUPERSEDED)
        ->firstOrFail();

    expect($entry->payload)->toBe(['hash' => $admission->token_nonce_hash])
        ->and($admission->token_nonce_hash)->not->toBeNull();
});
```

Add the corresponding `use` statements at the top of
`AdmissionTokenStabilityTest.php` (created empty of imports beyond
`Schema`/`Admission` in Task 5):

```php
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\EntranceDeltaEntry;
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL — `Error: Call to undefined method Konventix\Admission\Actions\IssueAdmissionToken::current()`
(and, once that is fixed by hand-editing just the signature, a second run
would fail on `rotate()` the same way) — neither method exists before this
task.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;
use RuntimeException;

/**
 * Mints the KVX1 token that goes in an admission's QR code (ADR-0033,
 * design doc 2026-09-11 §5).
 *
 * Signs with the newest key that may still sign — retired keys keep
 * verifying but never sign again, which is what makes signing-key rotation
 * (`RotateEntranceSigningKey` — a different rotation from this class's own
 * `rotate()`, of the brand's Ed25519 key rather than one admission's nonce)
 * safe for tokens already in circulation.
 *
 * Two entry points, deliberately, and no third. `current()` is the read
 * path — a ticket render, a badge print, anything that just needs *a*
 * valid token for this admission — and is idempotent: two calls with
 * nothing in between produce the exact same token body, because the nonce
 * it embeds is deterministic (TKT-F-018), derived from the admission's id
 * and its stored `token_issued_at` rather than stored itself. `rotate()`
 * is the write path: it always advances `token_issued_at`, which changes
 * the derived nonce, stores its HMAC hash on the admission, and leaves a
 * `token_superseded` delta entry so an offline device learns the previous
 * token no longer admits. `VerifyAdmissionToken::isSuperseded()` is the
 * other half of the pair.
 *
 * There used to be a single `handle()` that minted a fresh, random nonce on
 * every call; its docblock claimed this stranded the previous QR, but
 * nothing ever compared a stored nonce, so nothing was ever actually
 * invalidated (design doc §2.1). Every caller has moved to whichever of the
 * two methods below matches what it was actually doing; there is no
 * `handle()` any more; a call site with neither name is a compile error,
 * not a silent third behaviour.
 *
 * Whether an admission that already exists ever gets a `token_nonce_hash`
 * without someone calling `rotate()` on it — a backfill command — is
 * Task 9's own decision; its reasoning is appended to this docblock once
 * that task lands, not asserted here ahead of it.
 */
final class IssueAdmissionToken
{
    /**
     * How long a minted token stays valid, measured from `token_issued_at`.
     * Long enough that an attendee can be sent their QR weeks ahead, short
     * enough that a leaked one does not outlive the event by much.
     */
    public const int LIFETIME_DAYS = 60;

    public function __construct(
        private readonly AdmissionTokenCodec $codec,
    ) {}

    /**
     * The token this admission's holder should already be carrying. Reads,
     * never writes, except the one-time mint below when nothing has ever
     * been issued.
     *
     * @param  list<string>  $dayIds  event-day ULIDs; empty means every day
     */
    public function current(Admission $admission, array $dayIds = []): string
    {
        if ($admission->token_nonce_hash === null || $admission->token_issued_at === null) {
            return $this->rotate($admission, $dayIds);
        }

        return $this->mint($admission, $admission->token_issued_at, $dayIds, $this->signingKeyFor($admission->brand_id));
    }

    /**
     * Supersedes whatever token this admission carried before. The only
     * path that calls this today is `current()`'s own first-mint branch —
     * a participant-facing rotation button is out of scope (design doc
     * §12); this method is where it will hook in later.
     *
     * The signing key is resolved *before* anything is written: a brand
     * with no active key must fail exactly the way the old `handle()` did
     * — loudly, and with nothing persisted — not half-rotate an admission
     * whose new hash no token was ever actually signed to match.
     *
     * @param  list<string>  $dayIds  event-day ULIDs; empty means every day
     */
    public function rotate(Admission $admission, array $dayIds = []): string
    {
        $key = $this->signingKeyFor($admission->brand_id);
        $issuedAt = now();
        $hash = $this->hashOf($this->nonceFor($admission->id, $issuedAt));

        DB::transaction(function () use ($admission, $hash, $issuedAt): void {
            $admission->forceFill([
                'token_nonce_hash' => $hash,
                'token_issued_at' => $issuedAt,
            ])->save();

            $entry = new EntranceDeltaEntry([
                'event_id' => $admission->event_id,
                'admission_id' => $admission->id,
                'kind' => EntranceDeltaEntry::KIND_TOKEN_SUPERSEDED,
                'payload' => ['hash' => $hash],
            ]);
            // brand_id from the admission, not ambient context (ADR-0010).
            $entry->forceFill(['brand_id' => $admission->brand_id])->save();
        });

        return $this->mint($admission, $issuedAt, $dayIds, $key);
    }

    /**
     * @param  list<string>  $dayIds
     */
    private function mint(Admission $admission, CarbonInterface $issuedAt, array $dayIds, EntranceSigningKey $key): string
    {
        $payload = new AdmissionTokenPayload(
            admissionId: $admission->id,
            eventId: $admission->event_id,
            nonce: $this->nonceFor($admission->id, $issuedAt),
            issuedAt: $issuedAt->getTimestamp(),
            expiresAt: $issuedAt->copy()->addDays(self::LIFETIME_DAYS)->getTimestamp(),
            dayIds: $dayIds,
        );

        return $this->codec->encode($payload, $key->kid, $key->private_key);
    }

    /**
     * Deterministic, not random (TKT-F-018 — ported as is): the admission
     * id plus the whole second it was (re)issued in is all `current()`
     * needs to reproduce the exact nonce an earlier call embedded, without
     * ever persisting the plaintext value. Not a secret in itself — the
     * Ed25519 signature is what a device actually trusts a token on, and
     * neither `admissionId` nor `token_issued_at` is hidden from whoever
     * already holds the token — it only has to be reproducible here and
     * change under `rotate()`. Whole seconds because `token_issued_at`'s
     * own column carries no finer precision once round-tripped through the
     * database (this class's own file header explains why); deriving from
     * anything finer would desync the moment this method first computes a
     * nonce (in memory, sub-second) from every later call against the same
     * admission reloaded from storage.
     */
    private function nonceFor(string $admissionId, CarbonInterface $issuedAt): string
    {
        return $admissionId.'.'.$issuedAt->getTimestamp();
    }

    private function hashOf(string $nonce): string
    {
        return hash_hmac('sha256', $nonce, (string) config('admission.token_pepper'));
    }

    private function signingKeyFor(string $brandId): EntranceSigningKey
    {
        // withoutBrandScope + explicit brand (ADR-0010): tokens are minted
        // from queue workers and console commands with no ambient brand.
        $key = EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('retired_at')
            ->whereNotNull('activated_at')
            ->where('activated_at', '<=', now())
            ->where('expires_at', '>', now())
            ->orderByDesc('activated_at')
            ->first();

        if ($key === null) {
            throw new RuntimeException(sprintf(
                'Brand [%s] has no active entrance signing key; run `admission:rotate-signing-key` '
                .'before issuing admission tokens (ADR-0033).',
                $brandId,
            ));
        }

        return $key;
    }
}
```

`IssueBadge.php` line 94 — the badge print is a read path:

```php
    private function tokenFor(Admission $admission): ?string
    {
        try {
            return $this->issueToken->current($admission);
        } catch (Throwable) {
            return null;
        }
    }
```

And its class docblock (lines 24-27), which is now literally true rather
than aspirational — `current()` reuses the stored nonce even when the
caller supplies none, not only when the caller supplies a token itself:

```php
 * The QR reuses the admission's existing token when one is supplied, and
 * otherwise asks `IssueAdmissionToken::current()` for one — which itself
 * reuses the admission's stored nonce rather than minting a new one, so
 * reprinting a badge never strands a QR already handed to someone (design
 * doc §5; this replaces the docblock's old, previously-false claim that a
 * fresh nonce was minted "when there is no signing key problem and none
 * was given").
```

Every `EntranceDayAndReentryTest.php`, `AdmissionTokenTest.php` and
`EntranceApiTest.php` call site is the same one-word rename, applied at
every line listed under **Files** above:

```php
// Before, at each of the 15 listed lines:
$token = app(IssueAdmissionToken::class)->handle($admission);
// or, the one call with an explicit $dayIds argument (EntranceDayAndReentryTest.php:151):
$token = app(IssueAdmissionToken::class)->handle($lane['admission'], [$days[1]['day_id']]);

// After:
$token = app(IssueAdmissionToken::class)->current($admission);
$token = app(IssueAdmissionToken::class)->current($lane['admission'], [$days[1]['day_id']]);
```

None of these fifteen sites test invalidation — each mints once for a
freshly created admission and either signs, inspects, or scans the result
— so `current()` is correct for all of them; none becomes `rotate()`.

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php app-modules/admission/tests/AdmissionTokenTest.php app-modules/admission/tests/EntranceDayAndReentryTest.php app-modules/admission/tests/EntranceApiTest.php app-modules/admission/tests/BadgeDocumentTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/src/Actions/IssueAdmissionToken.php app-modules/admission/src/Actions/IssueBadge.php app-modules/admission/tests/EntranceDayAndReentryTest.php app-modules/admission/tests/AdmissionTokenTest.php app-modules/admission/tests/EntranceApiTest.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Split IssueAdmissionToken into current() and rotate(), remove the ambiguous handle(), and move every caller (#72)."
```

---

### Task 7: The enforcement — `VerifyAdmissionToken` refuses a superseded nonce

**Files:**
- Modify: `app-modules/admission/src/Actions/VerifyAdmissionToken.php`
  (add an import after line 6, add a method after line 59)
- Modify: `app-modules/admission/src/Actions/RecordScan.php` (docblock
  lines 25-30; new check inserted between lines 90 and 92)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
  (append)

**Interfaces:**
- Consumes: `IssueAdmissionToken::current()`/`rotate()` (Task 6);
  `admissions.token_nonce_hash` and `config('admission.token_pepper')`
  (Task 5); `CheckInRejectionReason::NonceSuperseded` (existing case,
  previously referenced nowhere).
- Produces: `VerifyAdmissionToken::isSuperseded(AdmissionTokenPayload
  $payload, Admission $admission): bool`, and `RecordScan::handle()`
  rejecting with `CheckInRejectionReason::NonceSuperseded` through its
  existing `reject()`/`record()`/`CheckInRejected` path. Task 10's metric
  listener consumes the resulting `CheckInRejected` event (unchanged
  signature — only a new value the existing `reasonCode` field can hold).

- [ ] **Step 1: Write the failing test**

```php
it('refuses a token minted before rotation with NonceSuperseded, through RecordScan, and accepts one for an admission with no stored hash', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);
    $key = EntranceSigningKey::withoutBrandScope()->where('brand_id', $brand->id)->firstOrFail();

    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);
    $issuer = app(IssueAdmissionToken::class);

    $stale = $issuer->current($admission);

    $this->travel(1)->second();
    $issuer->rotate($admission->fresh());

    $rejected = app(RecordScan::class)->handle(
        token: $stale,
        brandId: $brand->id,
        eventId: $eventId,
        clientScanId: 'stale-1',
    );

    expect($rejected->result)->toBe(CheckInResult::Rejected)
        ->and($rejected->reason_code)->toBe(CheckInRejectionReason::NonceSuperseded->value);

    // An admission with no stored hash — nothing has rotated for it since
    // before this enforcement shipped — accepts anything: this is what
    // keeps every token already in circulation working across the deploy.
    $preDeploy = Admission::factory()->create(['event_id' => $eventId]);
    $preDeployToken = (new AdmissionTokenCodec)->encode(
        new AdmissionTokenPayload(
            admissionId: $preDeploy->id,
            eventId: $eventId,
            nonce: (string) Str::ulid(),
            issuedAt: now()->subDays(10)->getTimestamp(),
            expiresAt: now()->addDays(50)->getTimestamp(),
        ),
        $key->kid,
        $key->private_key,
    );

    $accepted = app(RecordScan::class)->handle(
        token: $preDeployToken,
        brandId: $brand->id,
        eventId: $eventId,
        clientScanId: 'pre-deploy-1',
    );

    expect($accepted->result)->toBe(CheckInResult::Accepted);
});
```

Add to `AdmissionTokenStabilityTest.php`'s `use` block:

```php
use Illuminate\Support\Str;
use Konventix\Admission\Actions\RecordScan;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL — `Failed asserting that two strings are equal.` (or the
Pest enum-comparison equivalent) on `expect($rejected->result)->toBe(CheckInResult::Rejected)`:
the stale token is still `CheckInResult::Accepted`, because
`VerifyAdmissionToken::isSuperseded()` does not exist yet and `RecordScan`
performs no such check.

- [ ] **Step 3: Implement**

`VerifyAdmissionToken.php` — new import after line 6, new method after
line 59 (before the closing `}`):

```php
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;
```

```php
    /**
     * Whether $payload's nonce is no longer the one $admission currently
     * carries (design doc §5, `CheckInRejectionReason::NonceSuperseded`).
     *
     * An admission with no stored hash — nothing has rotated since before
     * this enforcement shipped — accepts anything: that is what keeps
     * every token already in circulation working across the deploy,
     * exactly the gap design doc §2.1 found (nothing ever compared a
     * stored nonce). `IssueAdmissionToken`'s own docblock records why that
     * is not a temporary state a backfill will later close.
     *
     * Timing-safe comparison: this runs against whatever a scanner
     * presents, the same reasoning `AdmissionTokenCodec::verify()` gives
     * for treating an unverifiable token as a rejection rather than an
     * error, never a fatal one.
     */
    public function isSuperseded(AdmissionTokenPayload $payload, Admission $admission): bool
    {
        if ($admission->token_nonce_hash === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload->nonce, (string) config('admission.token_pepper'));

        return ! hash_equals($admission->token_nonce_hash, $expected);
    }
```

`RecordScan.php` — docblock (replacing lines 25-30):

```php
 * The order of checks mirrors what an offline device can decide for itself,
 * deliberately: signature, then expiry, then event, then whether the token
 * itself is still current, then day-scoping, then admission validity, then
 * re-entry. Signature, expiry and event are decidable from the token alone;
 * everything after — nonce-supersession included — needs local state the
 * device gets from the delta feed (its own hash-per-admission cache, design
 * doc §5) and its synced calendar. Keeping the server in the same order
 * means both sides give the same answer and the reason codes match.
```

`RecordScan.php` — new check inserted between the existing
`UnknownAdmission` rejection (ending line 90) and the `$profile` lookup
(line 92):

```php
            if ($admission === null) {
                return $this->reject($brandId, $eventId, null, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::UnknownAdmission);
            }

            if ($this->verifyToken->isSuperseded($payload, $admission)) {
                return $this->reject($brandId, $eventId, $admission->id, null, $clientScanId, $scannedAt, $checkpoint, $device, $direction, CheckInRejectionReason::NonceSuperseded);
            }

            $profile = EntranceEventProfile::withoutBrandScope()
```

`reject()` already records the `CheckIn` row with `reason_code` and emits
`CheckInRejected` with `reasonCode` — this is the "same path it uses for
its other rejection reasons" the task calls for; nothing else in
`RecordScan` changes.

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/src/Actions/VerifyAdmissionToken.php app-modules/admission/src/Actions/RecordScan.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Refuse a superseded admission token at the door, and accept one still on file with no stored hash (#72)."
```

---

### Task 8: Offline — the delta feed carries the new hash

**Files:**
- Modify: `app-modules/admission/src/Http/Controllers/EntranceController.php`
  (lines 98-101, the `delta()` entry map)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
  (append)

The migration and model work for `entrance_delta_entries.payload` already
landed in Task 5; this task is the controller passthrough and the
docblock reasoning §5 asks to be written down, not only planned.

**Interfaces:**
- Consumes: `entrance_delta_entries.payload` (Task 5);
  `IssueAdmissionToken::rotate()` writing `KIND_TOKEN_SUPERSEDED` entries
  with `payload = ['hash' => ...]` (Task 6).
- Produces: `GET /api/entrance/delta`'s JSON response gaining a `payload`
  key on every entry (`null` for `revoked`/`checked_in`, `{"hash":
  "..."}` for `token_superseded`). Consumed by the offline scanner client
  (`resources/js/entrance/lib/evaluate.ts`/`token.ts`) — outside this
  section's file ownership (see the note after Step 3) — and by whatever
  future device-side hash cache reads this feed.

- [ ] **Step 1: Write the failing test**

```php
it('carries the new hash through the delta feed on a token_superseded entry', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);

    $checkpoint = Checkpoint::factory()->forEvent($eventId)->create();
    $plainDeviceToken = Str::random(48);
    EntranceDevice::factory()->withToken($plainDeviceToken)->create(['checkpoint_id' => $checkpoint->id]);

    app(IssueAdmissionToken::class)->rotate($admission);

    $response = $this->getJson('/api/entrance/delta?since=0', [
        'Authorization' => 'Bearer '.$plainDeviceToken,
        'Accept' => 'application/json',
    ])->assertOk();

    $entry = collect($response->json('entries'))->firstWhere('kind', 'token_superseded');

    expect($entry)->not->toBeNull()
        ->and($entry['admission_id'])->toBe($admission->id)
        ->and($entry['payload'])->toBe(['hash' => $admission->fresh()->token_nonce_hash]);
});
```

Add to `AdmissionTokenStabilityTest.php`'s `use` block:

```php
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
```

This deliberately does not reuse `EntranceApiTest.php`'s
`entranceTestLane()` helper — that function is declared inside a
`*Test.php` file, not `tests/Helpers.php`, so under `--parallel` a second
file's worker never loads it (`.ai/rules/tests.md`); the four lines above
are the same setup, inlined, rather than moving that helper and touching
a file this section does not own.

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL — `Failed asserting that null matches expected array{"hash": ...}`
(PHP itself raises `Warning: Undefined array key "payload"` first, which
Pest surfaces as the same failing expectation): `EntranceController::
delta()` does not put a `payload` key on a mapped entry yet.

- [ ] **Step 3: Implement**

```php
            'entries' => $entries->map(fn (EntranceDeltaEntry $entry): array => [
                'admission_id' => $entry->admission_id,
                'kind' => $entry->kind,
                'payload' => $entry->payload,
            ])->all(),
```

This replaces the three-line map in `delta()` (originally lines 98-101);
everything else in the method — the cursor, `reentry_policy`, `days`,
`poll_seconds`, `stale_after_seconds` — is unchanged.

Add the offline reasoning to the method's docblock, right after the
existing `next`/cursor paragraph:

```php
     * A `token_superseded` entry's `payload.hash` is what makes §5's
     * enforcement work offline at all: a device remembers the newest hash
     * per admission id and refuses a scan whose token hashes to something
     * else. A device that has never seen an admission for this event has
     * no hash on file and therefore accepts — failing open offline is
     * deliberate. A freshly provisioned scanner, or one an operator just
     * reset, must not turn away an entire queue because its local cache is
     * empty; the delta feed only ever tells it about tokens rotated after
     * the device started asking. Online verification has no such gap — it
     * always reads `admissions.token_nonce_hash` straight from the
     * database (`VerifyAdmissionToken::isSuperseded()`) and is exact, so
     * this asymmetry is bounded to the door, never the server's own
     * decision.
```

**A note on the offline scanner client.** §5 describes a device comparing
a scanned token's nonce hash against this cache; the TypeScript rejection
codes it already needs
(`resources/js/entrance/lib/evaluate.ts`'s `REJECTION` object) already
include `NonceSuperseded: 'nonce_superseded'`, pinned against
`CheckInRejectionReason` by `evaluate.test.ts` — nothing to add there.
Wiring the actual hash comparison into `evaluate.ts`/`token.ts`'s
`LocalState` is the client-side half of this feature; it is not one of
the six files this section owns (`app-modules/admission/src/Actions/
IssueAdmissionToken.php`, `VerifyAdmissionToken.php`, `RecordScan.php`,
`Models/EntranceDeltaEntry.php`, `Http/Controllers/EntranceController.php`
and the two migrations), and is left for whichever task in this plan owns
the offline entrance client next.

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php app-modules/admission/tests/EntranceApiTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/src/Http/Controllers/EntranceController.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Carry the superseded token's hash through the entrance delta feed, and document failing open offline (#72)."
```

---

### Task 9: Backfilling — why no command runs

**Files:**
- Modify: `app-modules/admission/src/Actions/IssueAdmissionToken.php`
  (class docblock only — replaces the one-line pointer Task 6 left at its
  end; no method body changes)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
  (append)

**Interfaces:**
- Consumes: `IssueAdmissionToken.php`'s own source text (Task 6);
  `VerifyAdmissionToken::isSuperseded()`'s null-hash branch (Task 7); the
  house replay-command pattern (`event:replay-details`,
  `identity:replay-profiles`), read only, never imported.
- Produces: nothing callable — a guarded decision. No later task in this
  section depends on an interface from this one.

**The decision.** Existing admissions have no stored `token_nonce_hash`.
`event:replay-details` (`app-modules/event/src/Console/
ReplayEventDetailsCommand.php`) is the house pattern for exactly this
situation — a read model that missed rows created before its projector
existed — and it names its own precedent, `identity:replay-profiles`, in
its own docblock. Both work because the column they backfill is
reconstructible from a source of truth that still exists: `Event`'s own
`starts_at`/`ends_at`/`timezone` row, an account's own current name. A
replay command re-emits the *current* state as a contract event, and every
downstream projector upserts, so a second run converges — idempotent by
construction.

`token_nonce_hash` has no such source. The plaintext nonce a pre-Task-6
`handle()` embedded was `Str::ulid()` — random, and never written to a
column, never carried on an event, never anywhere a command could read it
back from. There is nothing to replay. Worse: unlike `event:replay-details`,
which reconstructs the *same* facts it always would have, a command that
called `current()` for every existing admission would not reconstruct
anything — the deterministic nonce it derives (`admissionId . '.' .
token_issued_at`) has no relationship to whatever random nonce is sitting
inside a ticket a participant already downloaded. Running it would
immediately supersede every token in circulation, which is the exact
failure this whole feature exists to prevent, not a gap it needs to close.

So: the accept-when-absent rule in `VerifyAdmissionToken::isSuperseded()`
(Task 7) is not a stopgap for a backfill still to come. It is permanent,
for every admission whose token predates this migration, for as long as
that admission's token is never rotated. This task writes that reasoning
into `IssueAdmissionToken`'s class docblock, under "Deliberately no
`admission:backfill-token-hashes` command", replacing the one-line pointer
Task 6 left there — and pins it with a test, so a future edit cannot
quietly drop the explanation without a suite failure to notice.

- [ ] **Step 1: Write the failing test**

```php
it('documents in IssueAdmissionToken why token_nonce_hash needs no backfill command', function (): void {
    $source = file_get_contents(dirname(__DIR__).'/src/Actions/IssueAdmissionToken.php');

    expect($source)->not->toBeFalse()
        ->and($source)->toContain('backfill')
        ->and($source)->toContain('event:replay-details');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL —
`Failed asserting that '<?php...final class IssueAdmissionToken...' contains "event:replay-details".`
Task 6's docblock already says the word "backfill" in its one-line pointer
("a backfill command — is Task 9's own decision"), so the first assertion
passes; it names no house-pattern command at all yet, so the second one is
where this genuinely fails.

- [ ] **Step 3: Implement**

Replace the one-line pointer Task 6 left at the end of
`IssueAdmissionToken`'s class docblock ("Whether an admission that already
exists ever gets a `token_nonce_hash`... once that task lands.") with the
full reasoning:

```php
 *
 * **Deliberately no `admission:backfill-token-hashes` command.**
 * `event:replay-details` and `identity:replay-profiles` are this
 * codebase's house pattern for populating a read model that missed rows
 * created before its projector existed, and it works there because the
 * *source of truth* — Event's own `starts_at`/`timezone`, an account's own
 * name — still lives in a row the command can re-read and re-emit. There
 * is no analogous source for a nonce: the old `handle()` minted one with
 * `Str::ulid()`, and it was never captured anywhere — not in a column, not
 * in an event payload, the one place TKT-F-018 explicitly forbids storing
 * it. A command that called `current()` for every existing admission would
 * not backfill anything; it would mint a brand-new deterministic nonce,
 * store its hash, and immediately supersede every token already printed or
 * downloaded before the deploy — the exact regression this feature exists
 * to prevent. The null-hash branch in `VerifyAdmissionToken::
 * isSuperseded()` is not a stand-in for a backfill that has not shipped
 * yet; it is the only correct behaviour, permanently, for every admission
 * whose token predates this migration.
 */
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/src/Actions/IssueAdmissionToken.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Document why token_nonce_hash needs no backfill command, and pin the reasoning with a test (#72)."
```

---

### Task 10: The metric — `admission_tokens_superseded_total{brand}`

**Files:**
- Create: `app-modules/admission/src/Metrics/AdmissionMetrics.php`
- Create: `app-modules/admission/src/Listeners/Metrics/RecordAdmissionTokenMetrics.php`
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php`
  (imports after line 8 and after line 15; new `boot()` override inserted
  after line 47; new `registerMetrics()` method; one new `Event::listen`
  line inside `registerListeners()`, before its closing brace at line 117)
- Test: `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
  (append)

**Interfaces:**
- Consumes: `CheckInRejected` (existing event, unchanged signature, emitted
  by `RecordScan::reject()` — Task 7 is what makes it ever carry
  `reasonCode === CheckInRejectionReason::NonceSuperseded->value`);
  `Konventix\Platform\Observability\MetricCatalog::register()` and
  `Konventix\Platform\Observability\Metrics::increment()` (existing
  Platform services, both outside this section).
- Produces: the `admission_tokens_superseded_total{brand}` counter.
  **Consumed by the observability dashboard task (design doc §9): that
  task adds the single `konventix-sale` dashboard row holding all three of
  this feature's metrics** (`admission_tickets_rendered_total`,
  `event_calendar_downloads_total` from the ticket-renderer and calendar
  sections, and this one) by editing `contrib/observability/src/sale.mjs`
  and running `npm run observability:build`. This task deliberately stops
  at registering and recording the metric — no `.mjs` edit here — so
  `ObservabilityCoverageTest`'s "every declared metric is on at least one
  dashboard" check will fail on this metric specifically until that later
  task lands; `composer verify -- admission` does not run that root-level
  test (`tests/Feature/ObservabilityCoverageTest.php` is outside
  `app-modules/admission/tests`), so this task's own verification stays
  green, and the gap is closed before the merge request's full pipeline
  run, which does reach it.

- [ ] **Step 1: Write the failing test**

```php
it('increments admission_tokens_superseded_total when a scan is refused for a superseded nonce', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);
    $issuer = app(IssueAdmissionToken::class);

    $stale = $issuer->current($admission);
    $this->travel(1)->second();
    $issuer->rotate($admission->fresh());

    app(RecordScan::class)->handle(
        token: $stale,
        brandId: $brand->id,
        eventId: $eventId,
        clientScanId: 'metric-1',
    );

    $rendered = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

    expect($rendered)->toContain('konventix_admission_tokens_superseded_total{brand="'.$brand->slug.'"} 1');
});
```

Add to `AdmissionTokenStabilityTest.php`'s `use` block:

```php
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`
Expected: FAIL — `Failed asserting that '...' contains "konventix_admission_tokens_superseded_total{brand=\"...\"} 1".`
The metric is not declared and nothing increments it, so the rendered
registry has no such line at all.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Admission's entries in the metric catalogue (ADR-0059, design doc §9).
 * Only the one this feature owns here: the ticket PDF and calendar
 * counters belong to the plan sections that render them. Incremented by
 * `Listeners\Metrics\RecordAdmissionTokenMetrics`. The `konventix-sale`
 * dashboard row for all three of this feature's metrics is a later task's
 * (design doc §9) — this class only has to be declared, or
 * `ObservabilityCoverageTest` has nothing to check that later panel
 * against.
 */
final class AdmissionMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'admission_tokens_superseded_total',
                MetricKind::Counter,
                "Scans refused because the presented token's nonce no longer matches the admission's current one.",
                ['brand'],
            ),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners\Metrics;

use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * `admission_tokens_superseded_total` (design doc §9): the one rejection
 * reason among `CheckInRejected`'s nine that tells an operator whether
 * §5's new enforcement is turning away real people — not the door's whole
 * rejection tally, which is already readable from `check_ins`.
 *
 * Scoped bindings are resolved inside handle(), never the constructor
 * (`.ai/rules/listeners.md`) — this listener has none to resolve, but
 * `Metrics` itself is fetched via `app()` here rather than injected for
 * the same reason every other `Listeners/Metrics` recorder in this
 * codebase does.
 */
final class RecordAdmissionTokenMetrics
{
    public function handle(CheckInRejected $event): void
    {
        if ($event->reasonCode !== CheckInRejectionReason::NonceSuperseded->value) {
            return;
        }

        app(Metrics::class)->increment('admission_tokens_superseded_total', [
            'brand' => BrandSlugs::of($event->brandId),
        ]);
    }
}
```

`AdmissionServiceProvider.php` — new imports (alphabetical among the
existing `Konventix\Admission\*` block, lines 7-15):

```php
use Illuminate\Support\Facades\Event;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Gdpr\AdmissionGdprHandler;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationAmended;
use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationConfirmed;
use Konventix\Admission\Listeners\IssueCrewAdmissionOnAssignmentAccepted;
use Konventix\Admission\Listeners\Metrics\RecordAdmissionTokenMetrics;
use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;
use Konventix\Admission\Listeners\ProjectReentryPolicyOnEventReentryPolicyChanged;
use Konventix\Admission\Listeners\RevokeAdmissionsOnRegistrationCancelled;
use Konventix\Admission\Metrics\AdmissionMetrics;
```

and, among the `Konventix\Platform\*` imports:

```php
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
```

New `boot()` override, inserted directly after `moduleName()` (originally
ending line 47), pushing `defaultRoles()` and everything after it down —
matching where `ResourcesServiceProvider` and `MessagingServiceProvider`
both place theirs:

```php
    public static function moduleName(): string
    {
        return 'admission';
    }

    /**
     * `parent::boot()` first — routes, listeners, slots and the GDPR
     * handler come from the base class hooks; the metric catalogue is the
     * one thing the base class has no hook for (ADR-0059), the same
     * reasoning `ResourcesServiceProvider`/`MessagingServiceProvider` give.
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
    }

    /**
     * Admission's metrics (ADR-0059): catalogue entry here, recorder in
     * registerListeners() below.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...AdmissionMetrics::definitions());
    }
```

One new line inside `registerListeners()`, added before its closing brace
(originally line 117), after the existing `AssignmentAccepted` listener:

```php
        // Admission's own metric (ADR-0059, design doc §9): the one
        // rejection reason among CheckInRejected's nine that answers
        // whether §5's enforcement is rejecting real people.
        Event::listen(
            CheckInRejected::class,
            RecordAdmissionTokenMetrics::class,
        );
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionTokenStabilityTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/src/Metrics/AdmissionMetrics.php app-modules/admission/src/Listeners/Metrics/RecordAdmissionTokenMetrics.php app-modules/admission/src/Providers/AdmissionServiceProvider.php app-modules/admission/tests/AdmissionTokenStabilityTest.php
git commit -m "Register and record admission_tokens_superseded_total for the observability dashboard task to chart (#72)."
```
# Tasks 11–18: the read models a ticket and a calendar attachment need

> Companion to `00-header.md`. Read `docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md` §13 (the three 2026-09-11 amendments about read models) before touching any file below — it is the binding contract these eight tasks implement.

Six things happen across these eight tasks: Admission learns people's names (Task 11); Admission learns event facts (Tasks 12–13); `event:replay-details` learns to re-fire a venue (Task 14); Admission learns who bought a registration (Task 15); existing admissions get their buyer backfilled (Tasks 16–17); Registration's own event summary learns times and a venue (Task 18). No task here reads another module's tables — every one of them projects from contract events (ADR-0004), exactly as `docs/architecture/module-map.md` and `.ai/rules/src.md` require.

**A note on the three-way asymmetry this section settles.** Two modules gain a table shaped like Event's `EventCreated`/`EventDetailsUpdated`/`EventRescheduled`/`Venue*` events (`admission_event_summaries`, new; `registration_event_summaries`, extended), and both need the identical "patch, never replace" discipline the spec calls out. But they differ in one respect: `admission_event_summaries` is brand new, so its `handleRescheduled()` must be able to *create* a row during a backfill (mirroring Resources' `ProjectEventWindow`) — Admission has never seen the event before. `registration_event_summaries` has existed since 2026-08-17 and Registration is a Core module (`$gateable = false`, always active for every brand), so by the time any reschedule — real or replayed — can reach it, `handleCreated()` has already produced the row; its `handleRescheduled()` therefore only ever patches. Tasks 12–13 and Task 18 each explain this in their own docblocks; this paragraph is why the two are not identical code.

**Deviation flagged up front:** point 2 lists `admission_event_summaries`' columns as `event_id, brand_id, name, slug, starts_at, ends_at, timezone, venue_label`. Both Tasks 12–13 and Task 18 add one more column beyond that list and beyond point 6's equivalent list for `registration_event_summaries`: a nullable `venue_id`. It is bookkeeping, not display data — `VenueUpdated` carries no `event_id` (a venue may be the primary venue of more than one event) and `VenueUnassigned` must be able to tell "this was our primary venue" from "some other venue was detached." Without storing the venue's own id, neither handler could find or verify the right row. `Konventix\Admission\Models\Person` and `admissions.buyer_user_id` match their required shapes exactly, with no extra columns.

---

### Task 11: Admission learns people's names

**Files:**
- Create: `app-modules/admission/database/migrations/2026_09_11_010000_create_admission_people_table.php`
- Create: `app-modules/admission/src/Models/Person.php`
- Create: `app-modules/admission/src/Listeners/ProjectPerson.php`
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert import after L13, insert imports after L18, extend `registerListeners()` L116–119)
- Test: `app-modules/admission/tests/PersonProjectionTest.php`

**Interfaces:**
- Consumes: `Konventix\Identity\Events\UserRegistered{userId, brandId, email, displayName, username, locale, registeredAt}`, `Konventix\Identity\Events\UserProfileUpdated{userId, email, displayName, username, tagline, statusEmoji, socialLinks, visibility, locale, updatedAt}`
- Produces: `Konventix\Admission\Models\Person` (table `admission_people`) — `id`, `display_name`, `email`; `Konventix\Admission\Listeners\ProjectPerson::handle(UserRegistered|UserProfileUpdated $event): void`. Later tasks (the ticket renderer's holder-name fallback) read `Person::query()->find($holderUserId)`.

Read `app-modules/registration/src/Listeners/ProjectRegistrant.php`, `app-modules/registration/src/Models/Registrant.php` and `app-modules/registration/database/migrations/2026_08_12_150100_create_registration_registrants_and_invoiced_at.php` first — this task copies that trio's shape exactly, renamed for Admission. The table is **not** brand-scoped for the same reason `registration_registrants` and `messaging_recipients` are not: accounts are operator-global (ADR-0016), so a person who registered under one brand is still the same person when a different brand's entrance API looks them up as a holder. `Person` therefore has no `BelongsToBrand` trait and no `HasUlids` — its primary key is the account's own ULID, assigned from the event payload, never generated, exactly like `Registrant` and `Recipient`.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\Person;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Admission's own copy of a person's display name (design doc §13,
 * 2026-09-11 amendment "the holder-name fallback drops the billing
 * recipient"): the ticket's holder band needs a name, and Admission may
 * not read Registration's `billing_recipient_name` nor Identity's `User`
 * model (ADR-0004) — so it keeps its own read model, mirroring
 * Registration's `ProjectRegistrant` and Messaging's `ProjectRecipient`.
 */
function admissionPersonRegisteredEvent(
    string $userId,
    string $brandId,
    string $displayName = 'Ada Lovelace',
    string $email = 'ada@example.test',
): UserRegistered {
    return new UserRegistered(
        userId: $userId,
        brandId: $brandId,
        email: $email,
        displayName: $displayName,
        username: Str::slug($displayName),
        locale: 'de',
        registeredAt: now()->toIso8601String(),
    );
}

it('projects UserRegistered into the person read model, idempotently', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $brand->id));
    event(admissionPersonRegisteredEvent($userId, $brand->id));

    $person = Person::query()->findOrFail($userId);

    expect(Person::query()->count())->toBe(1)
        ->and($person->display_name)->toBe('Ada Lovelace')
        ->and($person->email)->toBe('ada@example.test');
});

it('re-projects UserProfileUpdated over an existing row', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $brand->id));

    event(new UserProfileUpdated(
        userId: $userId,
        email: 'countess@example.test',
        displayName: 'Countess of Lovelace',
        username: 'countess-lovelace',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    $person = Person::query()->findOrFail($userId);

    expect($person->display_name)->toBe('Countess of Lovelace')
        ->and($person->email)->toBe('countess@example.test');
});

it('is not brand-scoped: a person projected under one brand is still found under another', function (): void {
    $firstBrand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(admissionPersonRegisteredEvent($userId, $firstBrand->id));

    // A second, unrelated brand context — Person carries no BelongsToBrand
    // scope (ADR-0016: accounts are operator-global), so the row is still
    // reachable without withoutBrandScope(). Registrant and Recipient hold
    // the same shape for the same reason.
    $this->withBrand();

    expect(Person::query()->find($userId))->not->toBeNull();
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/PersonProjectionTest.php`
Expected: FAIL with `Class "Konventix\Admission\Models\Person" not found`.
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/admission/database/migrations/2026_09_11_010000_create_admission_people_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of a person's display name (design doc §13,
     * 2026-09-11 amendment "the holder-name fallback drops the billing
     * recipient"). The ticket's holder band (§4.2) falls back from the
     * holder's own name to "Unassigned" — the middle step the design
     * originally named, the registration's billing recipient, is not
     * reachable from this module (ADR-0004) — so this table is what *is*
     * reachable: the linked holder's own display name, projected from
     * Identity's account events. Same shape as `registration_registrants`
     * and `messaging_recipients`, mirroring `Listeners\ProjectPerson`.
     *
     * The primary key IS the account's own ULID
     * (`Konventix\Identity\Events\UserRegistered::$userId`), not a
     * generated one: the row is a projection of a foreign entity, not an
     * entity of its own (ADR-0004). Not brand-scoped: accounts are
     * operator-global (ADR-0016) — a person registered under one brand is
     * still the same person when a different brand's entrance API looks
     * them up as a holder, exactly like `registration_registrants` and
     * `messaging_recipients`.
     *
     * Backfilled by the existing `identity:replay-profiles`
     * (`UserProfileUpdated` carries the full current profile) — no new
     * replay command needed.
     */
    public function up(): void
    {
        Schema::create('admission_people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->string('email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_people');
    }
};
```
```php
<?php
// app-modules/admission/src/Models/Person.php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admission's own copy of a person's display name (ADR-0004: this module
 * may not read Identity's `User` model), projected from Identity's account
 * events by `Listeners\ProjectPerson` — idempotent, replayable, rebuildable
 * from `identity:replay-profiles`.
 *
 * Exists for the ticket's holder-name fallback (design doc §13, 2026-09-11
 * amendment): the linked holder's own display name, else "Unassigned" —
 * the design's originally-named middle fallback, the registration's
 * billing recipient, is not reachable from this module at all.
 *
 * Deliberately NOT `BelongsToBrand` (accounts are operator-global,
 * ADR-0016) and NOT `HasUlids` (the key is assigned from the event
 * payload, never generated) — same shape as Registration's `Registrant`
 * and Messaging's `Recipient`. No factory: rows exist only through the
 * projection — tests emit the Identity event instead.
 *
 * @property string $id
 * @property string $display_name
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Person extends Model
{
    protected $table = 'admission_people';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'display_name', 'email'];
}
```
```php
<?php
// app-modules/admission/src/Listeners/ProjectPerson.php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\Person;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Projects Identity's account events into the person read model (upsert —
 * idempotent and replayable), mirroring Registration's `ProjectRegistrant`
 * and Messaging's `ProjectRecipient`. Registered in
 * `AdmissionServiceProvider::registerListeners()` for both events, and
 * backfilled by the existing `identity:replay-profiles` (it re-emits
 * `UserProfileUpdated`, which this listener already handles).
 *
 * The `Konventix\Identity\Events\` imports are the sanctioned exception
 * (ADR-0004).
 */
final class ProjectPerson
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        Person::query()->updateOrCreate(
            ['id' => $event->userId],
            ['display_name' => $event->displayName, 'email' => $event->email],
        );
    }
}
```
Modify `app-modules/admission/src/Providers/AdmissionServiceProvider.php`. After L13 (`use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;`) insert:
```php
use Konventix\Admission\Listeners\ProjectPerson;
```
After L18 (`use Konventix\Event\Events\EventReentryPolicyChanged;`) insert:
```php
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
```
Inside `registerListeners()`, replace L116–119
```php
        Event::listen(
            AssignmentAccepted::class,
            IssueCrewAdmissionOnAssignmentAccepted::class,
        );
```
with
```php
        Event::listen(
            AssignmentAccepted::class,
            IssueCrewAdmissionOnAssignmentAccepted::class,
        );

        // Admission's read model of a person's display name (design doc
        // §13, 2026-09-11 amendment): the ticket's holder-name fallback needs
        // one, and Admission may not read Identity's `User` model (ADR-0004).
        Event::listen(UserRegistered::class, ProjectPerson::class);
        Event::listen(UserProfileUpdated::class, ProjectPerson::class);
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/PersonProjectionTest.php` — Expected: PASS, 3 passed.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/admission/TODO.md`: add a checked item under the ticket work noting `admission_people`, `Person` and `ProjectPerson` — "Admission's own read model of a person's display name, projected from Identity's `UserRegistered`/`UserProfileUpdated`, backfilled by `identity:replay-profiles`; not brand-scoped (ADR-0016), mirrors `registration_registrants`."
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/database/migrations/2026_09_11_010000_create_admission_people_table.php \
        app-modules/admission/src/Models/Person.php \
        app-modules/admission/src/Listeners/ProjectPerson.php \
        app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/tests/PersonProjectionTest.php \
        app-modules/admission/TODO.md
git commit -m "$(cat <<'EOF'
Give Admission its own read model of a person's display name, projected from Identity's account events, for the ticket's holder-name fallback (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 12: Admission learns event facts — name, schedule, and the row that creates it

**Files:**
- Create: `app-modules/admission/database/migrations/2026_09_11_020000_create_admission_event_summaries_table.php`
- Create: `app-modules/admission/src/Models/EventSummary.php`
- Create: `app-modules/admission/src/Listeners/ProjectEventSummary.php`
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert import after L13, insert import before L18, extend `registerListeners()` after L124–125)
- Test: `app-modules/admission/tests/EventSummaryProjectionTest.php`

**Interfaces:**
- Consumes: `Konventix\Event\Events\EventCreated{eventId, brandId, slug, name, startsAt, endsAt, timezone, predecessorEventId, visibility}`
- Produces: `Konventix\Admission\Models\EventSummary` (table `admission_event_summaries`, `BelongsToBrand`) — `event_id`, `brand_id`, `name`, `slug`, `starts_at`, `ends_at`, `timezone`, `venue_label`, `venue_id` (the deviation flagged above); `Konventix\Admission\Listeners\ProjectEventSummary::handleCreated(EventCreated $event): void` and its private `project(string $eventId, string $brandId, ?string $name = null, ?string $slug = null, ?string $startsAt = null, ?string $endsAt = null, ?string $timezone = null): void`, which Task 13 extends without changing its signature.

Read `app-modules/registration/src/Listeners/ProjectEventSummary.php`, `app-modules/registration/src/Models/EventSummary.php` and `app-modules/resources/src/Listeners/ProjectEventWindow.php` first (the latter is the "way Resources keeps `resource_event_windows`" the spec names). This table sits beside `app-modules/admission/src/Models/EntranceEventProfile.php`, which holds only the day calendar and the re-entry policy the door needs offline (ADR-0033) — that table is **not** extended for this; a ticket render is a different concern from an offline entrance decision, and the design explicitly keeps them apart (§13: "sits beside `entrance_event_profiles`... and must not be extended for this").

Every column but the identity pair (`brand_id`, `event_id`) is nullable, because a row's fields arrive on different contract events over time: `EventCreated` (this task) can set everything at once, but `EventRescheduled` (Task 13) can also *create* a row during a backfill with only the schedule, leaving `name`/`slug` null until `EventDetailsUpdated` catches up moments later in the same replay. `project()` is written with that full, all-nullable-past-the-identity-pair signature now, in this task, even though only `handleCreated()` calls it fully here — so Task 13 adds methods without ever touching this one.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;

/**
 * Admission's read model of an event's name, schedule and primary venue
 * (design doc §13, 2026-09-11 amendment "Admission learns event facts"):
 * the ticket's event band (§4.2) needs a name, a local start and end, a
 * timezone and the venue's address, and Admission may not read Event's
 * models (ADR-0004) — so it keeps its own copy, the way Resources keeps
 * `resource_event_windows`.
 */
function admissionEventCreatedEvent(
    string $eventId,
    string $brandId,
    string $name = 'SXLan 2026',
    string $slug = 'sxlan-2026',
    string $startsAt = '2027-06-15 10:00:00',
    string $endsAt = '2027-06-17 18:00:00',
    string $timezone = 'Europe/Berlin',
): EventCreated {
    return new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: $slug,
        name: $name,
        startsAt: $startsAt,
        endsAt: $endsAt,
        timezone: $timezone,
        predecessorEventId: null,
    );
}

it('projects EventCreated into the event summary read model', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->brand_id)->toBe($brand->id)
        ->and($summary->name)->toBe('SXLan 2026')
        ->and($summary->slug)->toBe('sxlan-2026')
        ->and($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($summary->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->venue_label)->toBeNull();
});

it('converges on replay: firing EventCreated twice for the same event leaves one row with the latest values', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'Working Title'));
    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'SXLan 2026 — Final'));

    expect(EventSummary::where('event_id', $eventId)->count())->toBe(1);

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($summary->name)->toBe('SXLan 2026 — Final');
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/EventSummaryProjectionTest.php`
Expected: FAIL with `Class "Konventix\Admission\Models\EventSummary" not found`.
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/admission/database/migrations/2026_09_11_020000_create_admission_event_summaries_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission's read model of an event's name, schedule and primary
     * venue (design doc §13, 2026-09-11 amendment "Admission learns event
     * facts"): the ticket's event band (§4.2) needs a name, a local start
     * and end, a timezone and the venue's address, and Admission may not
     * read Event's models (ADR-0004) — so it keeps its own copy, projected
     * from Event's contract events, the way Resources keeps
     * `resource_event_windows`.
     *
     * Sits beside `entrance_event_profiles`, which holds only the day
     * calendar and the re-entry policy for the door (ADR-0033) — a
     * different concern, not extended for this.
     *
     * Every column but the identity pair (`brand_id`, `event_id`) is
     * nullable: a row's fields arrive on different contract events over
     * time (`EventCreated` now; later `EventDetailsUpdated`/
     * `EventRescheduled`/`VenueAssigned`), so the table must tolerate
     * partial knowledge at any point — same reasoning as
     * `EntranceEventProfile`'s nullable `poll_seconds`. `venue_id` is
     * bookkeeping, not itself printed on the ticket or the calendar: it is
     * what lets `Listeners\ProjectEventSummary::handleVenueUpdated()` find
     * this row again when the venue is renamed, and lets
     * `handleVenueUnassigned()` tell its own primary venue apart from any
     * other.
     */
    public function up(): void
    {
        Schema::create('admission_event_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_id')->constrained()->cascadeOnDelete();

            // Event is a different module — plain ULID, no FK (ADR-0014).
            $table->ulid('event_id');

            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('venue_label')->nullable();
            $table->ulid('venue_id')->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_event_summaries');
    }
};
```
```php
<?php
// app-modules/admission/src/Models/EventSummary.php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Admission knows it (ADR-0004: this module may not read
 * Event's models, so it projects what it needs from Event's contract
 * events) — design doc §13, 2026-09-11 amendment "Admission learns event
 * facts".
 *
 * Exists for the ticket's event band (§4.2) and, through
 * `Konventix\Platform\Calendar\CalendarFile`, the calendar file's
 * `LOCATION`: a name, a local start and end plus the timezone they are
 * promised in (ADR-0027 — stored wall-clock, exactly as Event's own
 * `starts_at`/`ends_at`), and the primary venue's name and city.
 *
 * Sits beside `EntranceEventProfile`, which holds only the day calendar and
 * the re-entry policy the door needs offline (ADR-0033) — a different
 * concern, not extended for this.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated`/`EventRescheduled`/`VenueAssigned` rather than
 * inventing an event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $name
 * @property string|null $slug
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property string|null $venue_label
 * @property string|null $venue_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'admission_event_summaries';

    protected $fillable = ['event_id', 'name', 'slug', 'starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
```
```php
<?php
// app-modules/admission/src/Listeners/ProjectEventSummary.php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;

/**
 * Keeps Admission's copy of an event's name, schedule and primary venue
 * (ADR-0004: this module may not read Event's models, so it projects what
 * it needs from Event's contract events) — design doc §13, 2026-09-11
 * amendment "Admission learns event facts". Task 13 (2026-09-11) extends
 * this class with `handleDetailsUpdated()`, `handleRescheduled()` and the
 * three venue handlers; this task only wires `EventCreated`.
 *
 * `project()` already carries the full, all-nullable-past-the-identity-pair
 * signature every later handler needs, even though `handleCreated()` is the
 * only caller today — `array_filter` drops every null argument, so a call
 * that omits a field patches the row instead of blanking it. That is what
 * lets Task 13 add patch-only handlers without ever touching this method.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    private function project(
        string $eventId,
        string $brandId,
        ?string $name = null,
        ?string $slug = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?string $timezone = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}
```
Modify `app-modules/admission/src/Providers/AdmissionServiceProvider.php`. After L13 (`use Konventix\Admission\Listeners\ProjectEventDaysOnEventDaysChanged;`) insert:
```php
use Konventix\Admission\Listeners\ProjectEventSummary;
```
Before L18 (`use Konventix\Event\Events\EventDaysChanged;`) insert:
```php
use Konventix\Event\Events\EventCreated;
```
Inside `registerListeners()`, after the two lines added by Task 11 (`Event::listen(UserRegistered::class, ProjectPerson::class);` / `Event::listen(UserProfileUpdated::class, ProjectPerson::class);`), before the closing `}`, insert:
```php

        // Admission's read model of an event's name, slug, schedule and
        // venue (design doc §13, 2026-09-11 amendment "Admission learns event
        // facts"): the ticket's event band needs them, and Admission may not
        // read Event's models (ADR-0004).
        Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/EventSummaryProjectionTest.php` — Expected: PASS, 2 passed.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/admission/TODO.md`: add a checked item noting `admission_event_summaries`, `EventSummary` and `ProjectEventSummary::handleCreated()` — "Admission's own read model of an event's name and schedule, projected from `EventCreated`; sits beside `entrance_event_profiles` (ADR-0033), which is not extended for this."
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/database/migrations/2026_09_11_020000_create_admission_event_summaries_table.php \
        app-modules/admission/src/Models/EventSummary.php \
        app-modules/admission/src/Listeners/ProjectEventSummary.php \
        app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/tests/EventSummaryProjectionTest.php \
        app-modules/admission/TODO.md
git commit -m "$(cat <<'EOF'
Give Admission its own read model of an event's name and schedule, projected from EventCreated, for the ticket's event band (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 13: Admission's event summary learns to patch, never replace

**Files:**
- Modify: `app-modules/admission/src/Listeners/ProjectEventSummary.php` (append five methods; `project()` from Task 12 is unchanged)
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert imports after L20 and L21, extend `registerListeners()` after L133)
- Test: `app-modules/admission/tests/EventSummaryProjectionTest.php` (append test cases)

**Interfaces:**
- Consumes: `Konventix\Event\Events\EventDetailsUpdated{eventId, brandId, slug, name, visibility}`, `EventRescheduled{eventId, startsAt, endsAt, timezone, brandId}`, `VenueAssigned{eventId, venueId, brandId, name, city, isPrimary}`, `VenueUpdated{venueId, brandId, name, city, countryCode}`, `VenueUnassigned{eventId, venueId, brandId}`
- Produces: `ProjectEventSummary::{handleDetailsUpdated, handleRescheduled, handleVenueAssigned, handleVenueUpdated, handleVenueUnassigned}`; a fully-populated `admission_event_summaries.venue_label` in the fixed format `"{$venue->name}, {$venue->city}"` — Task 14's backfill and the (later, out-of-section) ticket renderer both rely on this exact format.

This is where the spec's central trap lives: `EventDetailsUpdated` carries a name and a slug but no times, and `EventRescheduled` carries times but no name or slug. `handleDetailsUpdated()` and `handleRescheduled()` each call the *same* `project()` from Task 12, passing only the fields their event carries — `array_filter` drops the rest, so neither call can blank what the other already set. `handleRescheduled()` is also the one method here allowed to *create* a row (via `project()`'s find-or-new): `event:replay-details`' venue-pass backfill (Task 14) fires the schedule before the details, for an event `admission_event_summaries` has never had a row for, so the row must be able to come into existence right there, with `name`/`slug` staying null for the moment until `handleDetailsUpdated()` catches up in the same replay.

The venue handlers are simpler and never create: by the time a `Venue*` event reaches this listener — live, or third in the replay's order — the row already exists from `handleCreated()` or `handleRescheduled()`. `handleVenueAssigned()` only acts on `isPrimary: true` (design §4.2/§6.1 — a secondary venue never appears on a ticket or a calendar), storing both `venue_id` (bookkeeping) and the formatted `venue_label`. `handleVenueUpdated()` carries no `eventId` at all — the same venue can be the primary venue of more than one event — so it patches every row whose `venue_id` matches. `handleVenueUnassigned()` clears both columns, but only when the unassigned venue is the one this row currently points at.

- [ ] **Step 1: Write the failing test**
```php
// Append to app-modules/admission/tests/EventSummaryProjectionTest.php.
// Add these imports alongside the existing ones at the top of the file:
//   use Konventix\Event\Events\EventDetailsUpdated;
//   use Konventix\Event\Events\EventRescheduled;
//   use Konventix\Event\Events\VenueAssigned;
//   use Konventix\Event\Events\VenueUnassigned;
//   use Konventix\Event\Events\VenueUpdated;

it('a details update does not erase the times, and a reschedule does not erase the name', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(admissionEventCreatedEvent($eventId, $brand->id, name: 'Original Name', startsAt: '2027-06-15 10:00:00', endsAt: '2027-06-17 18:00:00'));

    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'renamed-slug',
        name: 'Renamed Event',
    ));

    $afterDetails = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($afterDetails->name)->toBe('Renamed Event')
        ->and($afterDetails->slug)->toBe('renamed-slug')
        ->and($afterDetails->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($afterDetails->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00');

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $afterReschedule = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($afterReschedule->name)->toBe('Renamed Event')
        ->and($afterReschedule->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00')
        ->and($afterReschedule->ends_at->format('Y-m-d H:i:s'))->toBe('2027-07-03 20:00:00');
});

it('creates the row from a reschedule that names its brand, for an event that predates the projection', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    // Simulates event:replay-details' backfill order for an event this
    // table never had a row for: the schedule arrives before the details.
    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00')
        ->and($summary->name)->toBeNull();

    event(new EventDetailsUpdated(eventId: $eventId, brandId: $brand->id, slug: 'backfilled', name: 'Backfilled Event'));

    expect($summary->fresh()->name)->toBe('Backfilled Event');
});

it('sets the venue label from the primary venue and ignores a non-primary one', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));

    event(new VenueAssigned(
        eventId: $eventId,
        venueId: (string) Str::ulid(),
        brandId: $brand->id,
        name: 'Side Room',
        city: 'Hamburg',
        isPrimary: false,
    ));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();

    $primaryVenueId = (string) Str::ulid();
    event(new VenueAssigned(
        eventId: $eventId,
        venueId: $primaryVenueId,
        brandId: $brand->id,
        name: 'Congress Centrum',
        city: 'Hamburg',
        isPrimary: true,
    ));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->venue_label)->toBe('Congress Centrum, Hamburg')
        ->and($summary->venue_id)->toBe($primaryVenueId);
});

it('renames the venue label on VenueUpdated and clears it on VenueUnassigned', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $venueId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));
    event(new VenueAssigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum', city: 'Hamburg', isPrimary: true));

    event(new VenueUpdated(venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum West', city: 'Hamburg', countryCode: 'DE'));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum West, Hamburg');

    event(new VenueUnassigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->venue_label)->toBeNull()
        ->and($summary->venue_id)->toBeNull();
});

it('an event with no venue leaves the label null, and a stray venue event for another event breaks nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(admissionEventCreatedEvent($eventId, $brand->id));

    event(new VenueUpdated(venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Unrelated Hall', city: 'Berlin', countryCode: 'DE'));
    event(new VenueUnassigned(eventId: (string) Str::ulid(), venueId: (string) Str::ulid(), brandId: $brand->id));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/EventSummaryProjectionTest.php`
Expected: FAIL — `Failed asserting that null matches expected 'Renamed Event'` (nothing listens to `EventDetailsUpdated` yet, so `handleCreated()`'s original name survives unpatched, and the row created directly by `EventRescheduled` in the second new test is never created at all).
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/admission/src/Listeners/ProjectEventSummary.php — full resulting file

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\EventSummary;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;

/**
 * Keeps Admission's copy of an event's name, schedule and primary venue
 * (ADR-0004) — design doc §13, 2026-09-11 amendments "Admission learns
 * event facts" and "`event:replay-details` gains a venue pass".
 *
 * `EventDetailsUpdated` carries a name and a slug but no times;
 * `EventRescheduled` carries times but no name or slug. Both call the same
 * `project()`, passing only what their own event carries — its
 * `array_filter` drops the rest, so neither call erases what the other set.
 * `handleRescheduled()` is the one handler that may also *create* the row:
 * `event:replay-details`' venue-pass backfill fires the schedule before the
 * details for an event this table has never seen, so the row must be able
 * to come into existence there, with `name`/`slug` staying null until
 * `handleDetailsUpdated()` arrives moments later in the same replay.
 *
 * The venue handlers never create — by the time any of them fires, the row
 * already exists from `handleCreated()` or `handleRescheduled()`.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    /**
     * `EventDetailsUpdated` carries no times: patches name and slug only.
     */
    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
        );
    }

    /**
     * `EventRescheduled` carries times but no name or slug — the opposite
     * gap from `handleDetailsUpdated()`. Unlike that method this may create
     * the row: see the class docblock.
     */
    public function handleRescheduled(EventRescheduled $event): void
    {
        if ($event->brandId === null) {
            return;
        }

        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    /**
     * Only the *primary* venue matters here (design §4.2, §6.1) — a
     * non-primary assignment is a no-op. `venue_id` is kept alongside the
     * label so `handleVenueUpdated()` can find this row again when the
     * venue is renamed, and so `handleVenueUnassigned()` can tell "this was
     * our primary venue" from "some other venue was unassigned".
     */
    public function handleVenueAssigned(VenueAssigned $event): void
    {
        if (! $event->isPrimary) {
            return;
        }

        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update([
                'venue_id' => $event->venueId,
                'venue_label' => "{$event->name}, {$event->city}",
            ]);
    }

    /**
     * `VenueUpdated` carries no `eventId` — a venue can be the primary
     * venue of more than one event — so this patches every row currently
     * pointing at it, found by the `venue_id` `handleVenueAssigned()` left
     * behind.
     */
    public function handleVenueUpdated(VenueUpdated $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_label' => "{$event->name}, {$event->city}"]);
    }

    public function handleVenueUnassigned(VenueUnassigned $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_id' => null, 'venue_label' => null]);
    }

    private function project(
        string $eventId,
        string $brandId,
        ?string $name = null,
        ?string $slug = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?string $timezone = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}
```
Modify `app-modules/admission/src/Providers/AdmissionServiceProvider.php`. After L20 (`use Konventix\Event\Events\EventDaysChanged;`) insert:
```php
use Konventix\Event\Events\EventDetailsUpdated;
```
After L21 (`use Konventix\Event\Events\EventReentryPolicyChanged;`) insert:
```php
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
```
Inside `registerListeners()`, after the line Task 12 added (`Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);`, now at L133) insert:
```php
        Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);
        Event::listen(VenueAssigned::class, [ProjectEventSummary::class, 'handleVenueAssigned']);
        Event::listen(VenueUpdated::class, [ProjectEventSummary::class, 'handleVenueUpdated']);
        Event::listen(VenueUnassigned::class, [ProjectEventSummary::class, 'handleVenueUnassigned']);
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/EventSummaryProjectionTest.php` — Expected: PASS, 7 passed.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/admission/TODO.md`: extend the Task 12 item — "…patches rather than replaces on `EventDetailsUpdated`/`EventRescheduled`, and learns the primary venue from `VenueAssigned`/`VenueUpdated`/`VenueUnassigned` (design §13)."
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Listeners/ProjectEventSummary.php \
        app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/tests/EventSummaryProjectionTest.php \
        app-modules/admission/TODO.md
git commit -m "$(cat <<'EOF'
Teach Admission's event summary to patch its schedule and its primary venue without erasing each other (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 14: `event:replay-details` gains a venue pass

**Files:**
- Modify: `app-modules/event/src/Console/ReplayEventDetailsCommand.php` (full-file rewrite; see below)
- Test: `app-modules/event/tests/ReplayEventDetailsCommandTest.php` (add imports after L9, append two `it()` blocks after L99)

**Interfaces:**
- Consumes: `Konventix\Event\Models\Event::primaryVenue(): ?Venue`, `Konventix\Event\Actions\AssignVenue::handle(Event $event, Venue $venue, bool $isPrimary = false): Event`
- Produces: `event:replay-details` now also emits `Konventix\Event\Events\VenueAssigned{eventId, venueId, brandId, name, city, isPrimary: true}` for each event's primary venue — this is what lets Task 13's `admission_event_summaries` and Task 18's `registration_event_summaries` backfill their `venue_label` for events that predate those projections. An event with no venue emits nothing here, matching what both tasks already tolerate.

Read `app-modules/event/src/Console/ReplayEventDetailsCommand.php` and `app-modules/event/tests/ReplayEventDetailsCommandTest.php` in full first. The command re-fires `EventRescheduled` and `EventDetailsUpdated`, which between them carry a name, a slug, the times and the timezone — but never a venue. Without this task, a backfilled `admission_event_summaries` or `registration_event_summaries` row would have a schedule and a name but a permanently-null `venue_label`, and both the ticket's event band and the calendar `LOCATION` would be silently empty for every event that existed before this feature shipped. The fix re-fires `VenueAssigned` for each event's *primary* venue only (`Event::primaryVenue()`), matching `AssignVenue`'s own contract that only `isPrimary: true` matters to either consumer (design §4.2, §6.1). The venue pass runs last, after the schedule and the details, because — as Task 13 documents — the row it attaches to must already exist.

- [ ] **Step 1: Write the failing test**
```php
// Append to app-modules/event/tests/ReplayEventDetailsCommandTest.php.
// Add these imports alongside the existing ones at the top of the file:
//   use Konventix\Event\Actions\AssignVenue;
//   use Konventix\Event\Events\VenueAssigned;
//   use Konventix\Event\Models\Venue;

it('re-emits VenueAssigned for the primary venue and nothing for an event without one', function (): void {
    $this->withBrand();
    $withVenue = EventModel::factory()->create();
    $venue = Venue::factory()->create(['name' => 'Congress Centrum', 'city' => 'Hamburg']);
    app(AssignVenue::class)->handle($withVenue, $venue, isPrimary: true);

    $withoutVenue = EventModel::factory()->create();

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class, VenueAssigned::class]);

    $this->artisan('event:replay-details')->assertSuccessful();

    Event::assertDispatchedTimes(VenueAssigned::class, 1);
    Event::assertDispatched(
        VenueAssigned::class,
        fn (VenueAssigned $e): bool => $e->eventId === $withVenue->id
            && $e->venueId === $venue->id
            && $e->brandId === $withVenue->brand_id
            && $e->name === 'Congress Centrum'
            && $e->city === 'Hamburg'
            && $e->isPrimary === true,
    );
    expect($withoutVenue)->not->toBeNull(); // the second event exists; VenueAssigned fired only once above.
});

it('does not re-emit VenueAssigned for a venue that is not primary', function (): void {
    $this->withBrand();
    $event = EventModel::factory()->create();
    $secondary = Venue::factory()->create();
    app(AssignVenue::class)->handle($event, $secondary, isPrimary: false);

    Event::fake([EventRescheduled::class, EventDetailsUpdated::class, VenueAssigned::class]);

    $this->artisan('event:replay-details', ['--event' => $event->id])->assertSuccessful();

    Event::assertNotDispatched(VenueAssigned::class);
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/ReplayEventDetailsCommandTest.php`
Expected: FAIL with `Expected event [Konventix\Event\Events\VenueAssigned] to be dispatched 1 times, instead dispatched 0 times.`
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/event/src/Console/ReplayEventDetailsCommand.php — full resulting file

declare(strict_types=1);

namespace Konventix\Event\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Models\Event;

/**
 * Re-emits `EventRescheduled`, `EventDetailsUpdated` and, for an event with
 * a primary venue, `VenueAssigned` — for every event (or one), carrying the
 * row's current values. Read models in other modules project from these
 * events and therefore only know events that changed after the projection
 * existed; Resources' `resource_event_windows` was the first that needed a
 * backfill when a brand switches the module on mid-season, and Admission's
 * `admission_event_summaries` (design doc §13) is the first that needs the
 * venue too. A replay rebuilds them from the contract, which is what
 * ADR-0004 promises — the same shape as `identity:replay-profiles`.
 *
 * Order matters and is deliberate: the schedule first, because it is the
 * event that can *create* a projection's row (it carries the times and,
 * since S0, the brand); the details second, because they only *name* a row
 * that already exists; the venue third, because without a row already
 * standing there is nothing to attach a `LOCATION` to. Idempotent by
 * construction: every consumer upserts.
 *
 * `VenueAssigned` is re-fired only for the event's *primary* venue
 * (`Event::primaryVenue()`) — a secondary venue is never re-emitted here,
 * matching `Konventix\Event\Actions\AssignVenue`'s own contract, where only
 * `isPrimary: true` matters to a ticket's event band or a calendar
 * `LOCATION` (design §4.2, §6.1). An event with no venue emits none; a
 * backfilled summary for it therefore has no venue, which every consumer
 * must already tolerate (design §11).
 *
 * Deliberately not `EventCreated`: several modules react to creation with
 * side effects a replay must not repeat.
 */
final class ReplayEventDetailsCommand extends Command
{
    protected $signature = 'event:replay-details
        {--event= : Replay one event by ULID instead of every event}
        {--chunk=200 : Events per database round trip}';

    protected $description = 'Re-emit EventRescheduled, EventDetailsUpdated and the primary VenueAssigned for existing events so downstream read models can be rebuilt';

    public function handle(): int
    {
        $only = $this->option('event');
        $only = is_string($only) && $only !== '' ? $only : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Event::withoutBrandScope()->with('venues');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $replayed = 0;

        $query->chunkById($chunk, function (Collection $events) use (&$replayed): void {
            /** @var Event $event */
            foreach ($events as $event) {
                event(new EventRescheduled(
                    eventId: $event->id,
                    startsAt: $event->starts_at->format('Y-m-d H:i:s'),
                    endsAt: $event->ends_at->format('Y-m-d H:i:s'),
                    timezone: $event->timezone,
                    brandId: $event->brand_id,
                ));

                event(new EventDetailsUpdated(
                    eventId: $event->id,
                    brandId: $event->brand_id,
                    slug: $event->slug,
                    name: $event->name,
                    visibility: $event->visibility->value,
                ));

                $venue = $event->primaryVenue();

                if ($venue !== null) {
                    event(new VenueAssigned(
                        eventId: $event->id,
                        venueId: $venue->id,
                        brandId: $event->brand_id,
                        name: $venue->name,
                        city: $venue->city,
                        isPrimary: true,
                    ));
                }

                $replayed++;
            }
        });

        if ($only !== null && $replayed === 0) {
            $this->components->error("No event [{$only}].");

            return self::FAILURE;
        }

        $this->components->info("Replayed {$replayed} event(s).");

        return self::SUCCESS;
    }
}
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/ReplayEventDetailsCommandTest.php` — Expected: PASS, 6 passed (the 4 pre-existing cases plus the 2 new ones).
- [ ] **Step 5: Verify and commit**
Also update `app-modules/event/TODO.md`: note that `event:replay-details` now also re-fires `VenueAssigned` for each event's primary venue, so a read model added after an event already has one can still backfill its location (design §13).
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- event`
```bash
git add app-modules/event/src/Console/ReplayEventDetailsCommand.php \
        app-modules/event/tests/ReplayEventDetailsCommandTest.php \
        app-modules/event/TODO.md
git commit -m "$(cat <<'EOF'
Make event:replay-details re-fire VenueAssigned for each event's primary venue, so a backfilled read model is not silently missing its location (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 15: Admission learns who bought — `buyer_user_id` threaded from confirmation and amendment

**Files:**
- Create: `app-modules/admission/database/migrations/2026_09_11_030000_add_buyer_user_id_to_admissions_table.php`
- Modify: `app-modules/admission/src/Actions/IssueAdmissionsForRegistration.php` (full-file rewrite; see below)
- Modify: `app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationConfirmed.php` L39–44
- Modify: `app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationAmended.php` L34–40
- Modify: `app-modules/admission/src/Models/Admission.php` (docblock L27–39, `$fillable` L48–58)
- Test: `app-modules/admission/tests/IssueAdmissionsFromRegistrationConfirmedTest.php` (append two `it()` blocks; reuses the file's existing `confirmedRegistrationEvent()` and `admissionTestAmendedEvent()` helpers — no new helper needed)

**Interfaces:**
- Consumes: `Konventix\Registration\Events\RegistrationConfirmed::$registrantUserId` (already on the event, per `app-modules/registration/src/Events/RegistrationConfirmed.php`), `RegistrationAmended::$registrantUserId` (already on the event)
- Produces: `admissions.buyer_user_id` (nullable ulid, no FK — Identity is a different module, ADR-0014); `IssueAdmissionsForRegistration::handle(string $registrationId, string $eventId, string $brandId, string $registrantUserId, array $grants, ?string $amendmentId = null): array` — the new `$registrantUserId` parameter is required and sits before `$grants`, so every existing named-argument call site keeps working once the two listeners are updated in this same task.

Read `app-modules/admission/src/Actions/IssueAdmissionsForRegistration.php`, its two listeners, and `app-modules/registration/src/Events/RegistrationConfirmed.php` first. `IssueAdmissionsForRegistration::handle()` currently receives `registrantUserId` from neither listener at all — both events already carry it (`RegistrationConfirmed` for the seat-claim authorisation in Resources, `RegistrationAmended` for the same reason), and the action's own `issueOne()` hard-sets only `holder_user_id` to null; nothing stores the buyer. This task threads it through unchanged in shape: the buyer is a *different* fact from the holder (design §13, "a registration-derived admission is anonymous today") — group and B2B bookings buy admissions for other people, so `buyer_user_id` and `holder_user_id` stay two independent nullable columns, never conflated.

- [ ] **Step 1: Write the failing test**
```php
// Append to app-modules/admission/tests/IssueAdmissionsFromRegistrationConfirmedTest.php.
// No new imports needed — Str, Admission and both event-builder helpers are
// already imported/declared earlier in this file.

it('threads the registrant as buyer_user_id on every admission issued at confirmation', function (): void {
    $this->withBrand();

    $event = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    event($event);

    $admissions = Admission::where('registration_id', $event->registrationId)->get();

    expect($admissions)->toHaveCount(2)
        ->and($admissions->pluck('buyer_user_id')->unique()->all())->toBe([$event->registrantUserId]);
});

it('threads the registrant as buyer_user_id on an admission issued by amendment', function (): void {
    $this->withBrand();

    $confirmed = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);
    event($confirmed);

    $amended = admissionTestAmendedEvent(
        [['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null]],
        $confirmed->registrationId,
        (string) Str::ulid(),
    );
    event($amended);

    $amendedAdmission = Admission::where('registration_id', $confirmed->registrationId)
        ->where('role', 'exhibitor_crew')
        ->firstOrFail();

    expect($amendedAdmission->buyer_user_id)->toBe($amended->registrantUserId);
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/IssueAdmissionsFromRegistrationConfirmedTest.php`
Expected: FAIL with `Illuminate\Database\Eloquent\MissingAttributeException: The attribute [buyer_user_id] either does not exist or was not retrieved for model [Konventix\Admission\Models\Admission].` (`Model::shouldBeStrict()` is on in non-production, per `CLAUDE_extra.md`).
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/admission/database/migrations/2026_09_11_030000_add_buyer_user_id_to_admissions_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who bought the registration this admission came from (design doc
     * §13, 2026-09-11 amendment "a registration-derived admission is
     * anonymous today"). `IssueAdmissionsForRegistration` hard-set
     * `holder_user_id` to null and dropped `registrantUserId` from both
     * `RegistrationConfirmed` and `RegistrationAmended`, so a purchased
     * admission named nobody at all until a holder claimed it later. The
     * buyer is a *different* fact from the holder — group and B2B bookings
     * buy admissions for other people — and the participant's own ticket
     * list (design §4.1) needs to tell "I bought this" apart from "I hold
     * this".
     *
     * Nullable because a crew admission (`assignment_id` set,
     * `registration_id` null — see `2026_08_12_220000`) has no purchase
     * behind it at all, and because rows written before this migration have
     * neither a stored value nor a way to derive one from their own data —
     * `registration:announce-ownership` (Task 16) backfills those from
     * Registration's side.
     *
     * Identity is a different module — plain ULID, no FK (ADR-0014).
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->ulid('buyer_user_id')->nullable()->after('holder_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropColumn('buyer_user_id');
        });
    }
};
```
```php
<?php
// app-modules/admission/src/Actions/IssueAdmissionsForRegistration.php — full resulting file

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Models\Admission;

/**
 * Issues one `Admission` per granted admission entry on a confirmed
 * registration — `{kind: 'admission'} × quantity` — using nothing but scalar
 * arguments (SPEC.md "Consumes" — `RegistrationConfirmed`, TODO.md "Listen to
 * RegistrationConfirmed and issue one admission per granted admission").
 *
 * Deliberately takes primitives, not the Registration event object: the
 * thin `IssueAdmissionsOnRegistrationConfirmed` listener unpacks the event
 * and calls this, so this class — and everything it touches — never needs to
 * know the event class exists. That is what makes "uses only the event
 * payload" a structural property instead of a convention.
 *
 * Idempotent and replayable (TODO.md), keyed on the *issuing occasion*
 * rather than the registration: `$amendmentId` is null for the original
 * confirmation and the amendment's ULID for each delta (ADR-0036). A plain
 * "does this registration have any admissions?" guard was sufficient while a
 * registration received grants exactly once, but it silently swallows every
 * amendment — so the guard is now per occasion, and each admission records
 * which one issued it. `withoutBrandScope()` is the named escape hatch
 * (ADR-0010): the check must hold regardless of which brand is ambient when
 * a replay happens, so it is scoped explicitly from the payload's own
 * `brandId` instead.
 *
 * `'admission'` is written as a literal rather than importing
 * `Konventix\Registration\Enums\GrantKind`: this module does not import
 * Registration at all (see
 * `tests/RegistrationCommerceImportBoundaryTest.php`), so the string is the
 * contract, not the enum.
 *
 * `$registrantUserId` is stored as `buyer_user_id` on every admission this
 * occasion issues (design doc §13, 2026-09-11 amendment "a
 * registration-derived admission is anonymous today"). It is a different
 * fact from `holder_user_id`, which stays null here and is filled later
 * through the existing admission claim links — a group or B2B booking buys
 * admissions for other people, so the buyer and the eventual holder are
 * never the same column.
 */
final class IssueAdmissionsForRegistration
{
    private const string ADMISSION_GRANT_KIND = 'admission';

    /**
     * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
     * @return list<Admission>
     */
    public function handle(
        string $registrationId,
        string $eventId,
        string $brandId,
        string $registrantUserId,
        array $grants,
        ?string $amendmentId = null,
    ): array {
        $alreadyIssued = Admission::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('registration_id', $registrationId)
            ->where('amendment_id', $amendmentId)
            ->exists();

        if ($alreadyIssued) {
            return [];
        }

        return DB::transaction(function () use ($registrationId, $eventId, $brandId, $registrantUserId, $grants, $amendmentId): array {
            $issued = [];

            foreach ($grants as $grant) {
                if ($grant['kind'] !== self::ADMISSION_GRANT_KIND) {
                    continue;
                }

                $quantity = $grant['quantity'];

                for ($unit = 0; $unit < $quantity; $unit++) {
                    $issued[] = $this->issueOne($brandId, $registrationId, $eventId, $registrantUserId, $grant['role'], $amendmentId);
                }
            }

            return $issued;
        });
    }

    private function issueOne(
        string $brandId,
        string $registrationId,
        string $eventId,
        string $registrantUserId,
        string $role,
        ?string $amendmentId,
    ): Admission {
        // brand_id comes from the event payload rather than the resolved
        // context, so a replay outside any brand context issues into the
        // right brand. It is deliberately not fillable — forceFill states the
        // exception explicitly instead of widening mass assignment.
        $admission = new Admission([
            'registration_id' => $registrationId,
            'amendment_id' => $amendmentId,
            'event_id' => $eventId,
            'holder_user_id' => null,
            'buyer_user_id' => $registrantUserId,
            'role' => $role,
            'valid_from' => null,
            'valid_until' => null,
            'state' => AdmissionState::Issued,
        ]);

        $admission->forceFill(['brand_id' => $brandId])->save();

        event(new AdmissionIssued(
            admissionId: $admission->id,
            registrationId: $admission->registration_id,
            eventId: $admission->event_id,
            brandId: $admission->brand_id,
            role: $admission->role,
            holderUserId: null,
            issuedAt: $admission->created_at->toIso8601String(),
        ));

        return $admission;
    }
}
```
Modify `app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationConfirmed.php` L39–44:
```php
        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            registrantUserId: $event->registrantUserId,
            grants: $event->grants,
        );
```
Modify `app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationAmended.php` L34–40:
```php
        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            registrantUserId: $event->registrantUserId,
            grants: $event->grants,
            amendmentId: $event->amendmentId,
        );
```
Modify `app-modules/admission/src/Models/Admission.php`. In the docblock (after L33 `@property string|null $holder_user_id`) insert:
```php
 * @property string|null $buyer_user_id
```
In `$fillable` (after `'holder_user_id',` on L53) insert:
```php
        'buyer_user_id',
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/IssueAdmissionsFromRegistrationConfirmedTest.php` — Expected: PASS, 11 passed (9 pre-existing plus the 2 new).
- [ ] **Step 5: Verify and commit**
Also update `app-modules/admission/TODO.md`: note `admissions.buyer_user_id`, threaded from `RegistrationConfirmed`/`RegistrationAmended`'s `registrantUserId`, distinct from `holder_user_id` (design §13). Also update `app-modules/admission/SPEC.md`'s "Owns" section for `Admission` to list `buyer_user_id` alongside `holder_user_id`.
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/database/migrations/2026_09_11_030000_add_buyer_user_id_to_admissions_table.php \
        app-modules/admission/src/Actions/IssueAdmissionsForRegistration.php \
        app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationConfirmed.php \
        app-modules/admission/src/Listeners/IssueAdmissionsOnRegistrationAmended.php \
        app-modules/admission/src/Models/Admission.php \
        app-modules/admission/tests/IssueAdmissionsFromRegistrationConfirmedTest.php \
        app-modules/admission/TODO.md \
        app-modules/admission/SPEC.md
git commit -m "$(cat <<'EOF'
Thread the registrant through RegistrationConfirmed/RegistrationAmended into admissions.buyer_user_id, distinct from the eventual holder (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 16: Registration announces ownership — `RegistrationOwnershipAnnounced` and its backfill command

**Files:**
- Create: `app-modules/registration/src/Events/RegistrationOwnershipAnnounced.php`
- Create: `app-modules/registration/src/Console/AnnounceRegistrationOwnershipCommand.php`
- Modify: `app-modules/registration/src/Providers/RegistrationServiceProvider.php` (insert import before L35, replace L584)
- Test: `app-modules/registration/tests/AnnounceRegistrationOwnershipCommandTest.php`

**Interfaces:**
- Consumes: `Konventix\Registration\Models\Registration{id, brand_id, registrant_user_id}` (read directly, inside the command only — this is a one-off backfill tool reading its own module's table, the same shape `identity:replay-profiles` and `event:replay-details` already use)
- Produces: `Konventix\Registration\Events\RegistrationOwnershipAnnounced{registrationId, brandId, registrantUserId}`; the `registration:announce-ownership {--chunk=500}` console command. Task 17 is this event's only listener.

Read `app-modules/event/src/Console/ReplayEventDetailsCommand.php` and `app-modules/identity/src/Console/ReplayProfilesCommand.php` first — this command follows their exact shape: `withoutBrandScope()`, `chunkById()`, a `--chunk` option, `$this->components->info(...)` summarising the count.

**Why this is a new event rather than a replay of `RegistrationConfirmed`.** `RegistrationConfirmed` also drives `Konventix\Registration\Listeners\MailOnRegistrationConfirmed` — re-emitting it to backfill one column would re-send every confirmation mail the operator has ever sent. `RegistrationOwnershipAnnounced` carries only the three fields a projection needs and nothing a mailer, a quota or an invoice could react to. **Only a projection may ever listen to it** — this is stated in both the event's own docblock and the command's below, and it is the entire reason the event exists as its own type instead of as a replay path on an existing one. A future PR adding a second listener to `RegistrationOwnershipAnnounced` is the mistake this section is written to prevent.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * `registration:announce-ownership` re-emits RegistrationOwnershipAnnounced
 * for every registration so Admission can backfill
 * `admissions.buyer_user_id` on rows written before that column existed
 * (design doc §13, 2026-09-11 amendment "a registration-derived admission
 * is anonymous today"). Same idea as `event:replay-details` and
 * `identity:replay-profiles`. Deliberately not a replay of
 * `RegistrationConfirmed`: that event also drives the confirmation mail,
 * and re-emitting it would re-send every one ever sent.
 */
it('emits one RegistrationOwnershipAnnounced per registration carrying its own registrant', function (): void {
    $brand = $this->withBrand();
    $first = Registration::factory()->confirmed()->create();
    $second = Registration::factory()->confirmed()->create();

    Event::fake([RegistrationOwnershipAnnounced::class]);

    $this->artisan('registration:announce-ownership')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationOwnershipAnnounced::class, 2);
    Event::assertDispatched(
        RegistrationOwnershipAnnounced::class,
        fn (RegistrationOwnershipAnnounced $e): bool => $e->registrationId === $first->id
            && $e->brandId === $brand->id
            && $e->registrantUserId === $first->registrant_user_id,
    );
    Event::assertDispatched(
        RegistrationOwnershipAnnounced::class,
        fn (RegistrationOwnershipAnnounced $e): bool => $e->registrationId === $second->id
            && $e->registrantUserId === $second->registrant_user_id,
    );
});

it('walks the table in chunks so a large operator does not load every registration at once', function (): void {
    $this->withBrand();
    Registration::factory()->count(3)->create();

    Event::fake([RegistrationOwnershipAnnounced::class]);

    $this->artisan('registration:announce-ownership', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(RegistrationOwnershipAnnounced::class, 3);
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/AnnounceRegistrationOwnershipCommandTest.php`
Expected: FAIL with `Command "registration:announce-ownership" is not defined.`
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/registration/src/Events/RegistrationOwnershipAnnounced.php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registration's buyer, restated for backfill (design doc §13, 2026-09-11
 * amendment "a registration-derived admission is anonymous today").
 * `admissions.buyer_user_id` is threaded from `RegistrationConfirmed` and
 * `RegistrationAmended` going forward (`IssueAdmissionsForRegistration`),
 * but a row written before that change has neither a stored value nor any
 * way to derive one from its own data. Admission's
 * `FillBuyerOnRegistrationOwnershipAnnounced` fills it in.
 *
 * This event exists — rather than `registration:announce-ownership`
 * re-firing `RegistrationConfirmed` itself — precisely so the backfill is
 * replay-safe: `RegistrationConfirmed` also drives
 * `Konventix\Registration\Listeners\MailOnRegistrationConfirmed`, and
 * re-emitting it would re-send every confirmation mail ever sent. This
 * event carries only the three fields a *projection* needs and nothing a
 * mailer, a quota or an invoice could react to. **Only a projection may
 * ever listen to it** — adding a second listener here is the mistake this
 * docblock exists to prevent.
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationOwnershipAnnounced
{
    public function __construct(
        public string $registrationId,
        public string $brandId,
        public string $registrantUserId,
    ) {}
}
```
```php
<?php
// app-modules/registration/src/Console/AnnounceRegistrationOwnershipCommand.php

declare(strict_types=1);

namespace Konventix\Registration\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * Re-emits `RegistrationOwnershipAnnounced` for every registration, so
 * Admission can backfill `admissions.buyer_user_id` on rows that predate
 * that column (design doc §13, 2026-09-11 amendment "a registration-derived
 * admission is anonymous today"). Same shape as `event:replay-details` and
 * `identity:replay-profiles`.
 *
 * Deliberately its own event rather than a replay of `RegistrationConfirmed`:
 * that event also drives the confirmation mail
 * (`MailOnRegistrationConfirmed`), and re-emitting it here would re-send
 * every confirmation mail ever sent. `RegistrationOwnershipAnnounced`
 * carries only the three fields a projection needs — **only a projection
 * may ever listen to it**, and this command must never grow into a general
 * "replay everything about a registration" tool.
 *
 * Idempotent on the consuming side by construction:
 * `FillBuyerOnRegistrationOwnershipAnnounced` only ever fills a null
 * `buyer_user_id`, so running this command any number of times, against any
 * subset of registrations, changes nothing once every admission has one.
 */
final class AnnounceRegistrationOwnershipCommand extends Command
{
    protected $signature = 'registration:announce-ownership {--chunk=500 : Registrations per database round trip}';

    protected $description = 'Re-emit RegistrationOwnershipAnnounced for every registration so Admission can backfill admissions.buyer_user_id';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $announced = 0;

        Registration::withoutBrandScope()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $registrations) use (&$announced): void {
                /** @var Registration $registration */
                foreach ($registrations as $registration) {
                    event(new RegistrationOwnershipAnnounced(
                        registrationId: $registration->id,
                        brandId: $registration->brand_id,
                        registrantUserId: $registration->registrant_user_id,
                    ));

                    $announced++;
                }
            });

        $this->components->info("Announced ownership for {$announced} registration(s).");

        return self::SUCCESS;
    }
}
```
Modify `app-modules/registration/src/Providers/RegistrationServiceProvider.php`. Before L35 (`use Konventix\Registration\Console\ExpireWaitlistOffersCommand;`) insert:
```php
use Konventix\Registration\Console\AnnounceRegistrationOwnershipCommand;
```
Replace L584:
```php
        $this->commands([ExpireWaitlistOffersCommand::class]);
```
with:
```php
        $this->commands([ExpireWaitlistOffersCommand::class, AnnounceRegistrationOwnershipCommand::class]);
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/AnnounceRegistrationOwnershipCommandTest.php` — Expected: PASS, 2 passed.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/registration/TODO.md`: note `RegistrationOwnershipAnnounced` and `registration:announce-ownership`, and that only a projection may ever listen to the event. Also update `app-modules/registration/SPEC.md`'s "Public contract" table with a `RegistrationOwnershipAnnounced` row: "A registration's buyer, restated for backfill only — never re-emits `RegistrationConfirmed`'s mail-triggering payload."
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- registration`
```bash
git add app-modules/registration/src/Events/RegistrationOwnershipAnnounced.php \
        app-modules/registration/src/Console/AnnounceRegistrationOwnershipCommand.php \
        app-modules/registration/src/Providers/RegistrationServiceProvider.php \
        app-modules/registration/tests/AnnounceRegistrationOwnershipCommandTest.php \
        app-modules/registration/TODO.md \
        app-modules/registration/SPEC.md
git commit -m "$(cat <<'EOF'
Add RegistrationOwnershipAnnounced and registration:announce-ownership as a replay-safe backfill path for admissions.buyer_user_id (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 17: Existing admissions get their buyer — Admission listens to `RegistrationOwnershipAnnounced`

**Files:**
- Create: `app-modules/admission/src/Listeners/FillBuyerOnRegistrationOwnershipAnnounced.php`
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert import before L10, insert import after L34, extend `registerListeners()` after L143)
- Test: `app-modules/admission/tests/BackfillBuyerOnRegistrationOwnershipAnnouncedTest.php`

**Interfaces:**
- Consumes: `Konventix\Registration\Events\RegistrationOwnershipAnnounced{registrationId, brandId, registrantUserId}` (Task 16), `admissions.buyer_user_id` (Task 15)
- Produces: `Konventix\Admission\Listeners\FillBuyerOnRegistrationOwnershipAnnounced::handle(RegistrationOwnershipAnnounced $event): void` — the last link in point 5's backfill chain; no later task depends on this class by name.

This listener is the whole reason Task 16's event and command exist. Its entire contract is one `whereNull('buyer_user_id')` guard: it fills a null buyer and never touches one `IssueAdmissionsForRegistration` (Task 15) already set, on either the very first delivery of an announcement or the thousandth replay of the same one. `Konventix\Registration\Events\RegistrationOwnershipAnnounced` is this module's second sanctioned import from Registration's `Events\` namespace (ADR-0004), alongside `RegistrationConfirmed` and `RegistrationAmended` — `tests/RegistrationCommerceImportBoundaryTest.php` already allows any class from that namespace, so no change is needed there.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
use Konventix\Registration\Models\Registration;

/**
 * `FillBuyerOnRegistrationOwnershipAnnounced` backfills
 * `admissions.buyer_user_id` for rows written before that column existed
 * (design doc §13, 2026-09-11 amendment "a registration-derived admission
 * is anonymous today"). Its whole contract is a `whereNull` guard: it never
 * overwrites a buyer `IssueAdmissionsForRegistration` already set.
 */
it('fills a null buyer_user_id and leaves an unrelated admission untouched', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $registrantUserId = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create();
    $unrelated = Admission::factory()->create();

    expect($admission->buyer_user_id)->toBeNull();

    event(new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: $registrantUserId,
    ));

    expect($admission->fresh()->buyer_user_id)->toBe($registrantUserId)
        ->and($unrelated->fresh()->buyer_user_id)->toBeNull();
});

it('leaves an already-set buyer_user_id alone', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $originalBuyer = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create(['buyer_user_id' => $originalBuyer]);

    event(new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: (string) Str::ulid(),
    ));

    expect($admission->fresh()->buyer_user_id)->toBe($originalBuyer);
});

it('is idempotent: replaying the same announcement twice changes nothing further', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $registrantUserId = (string) Str::ulid();

    $admission = Admission::factory()->forRegistration($registrationId)->create();

    $announcement = new RegistrationOwnershipAnnounced(
        registrationId: $registrationId,
        brandId: $admission->brand_id,
        registrantUserId: $registrantUserId,
    );

    event($announcement);
    event($announcement);

    expect($admission->fresh()->buyer_user_id)->toBe($registrantUserId);
});

it('backfills through the real registration:announce-ownership command end to end', function (): void {
    $this->withBrand();
    $registration = Registration::factory()->confirmed()->create();
    $admission = Admission::factory()->forRegistration($registration->id)->create();

    expect($admission->buyer_user_id)->toBeNull();

    $this->artisan('registration:announce-ownership')->assertSuccessful();

    expect($admission->fresh()->buyer_user_id)->toBe($registration->registrant_user_id);
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/BackfillBuyerOnRegistrationOwnershipAnnouncedTest.php`
Expected: FAIL — `Failed asserting that null matches expected '<ulid>'` (the event fires but nothing listens to it yet, so `buyer_user_id` stays null in every case).
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/admission/src/Listeners/FillBuyerOnRegistrationOwnershipAnnounced.php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\Admission;
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;

/**
 * Backfills `admissions.buyer_user_id` for rows written before that column
 * existed (design doc §13, 2026-09-11 amendment "a registration-derived
 * admission is anonymous today"). Registered in
 * `AdmissionServiceProvider::registerListeners()`.
 *
 * The `whereNull('buyer_user_id')` guard is the whole contract: this never
 * overwrites a value `IssueAdmissionsForRegistration` already set, so
 * running `registration:announce-ownership` any number of times, before or
 * after live traffic has filled some rows in, changes nothing on a row
 * that already has a buyer.
 *
 * `Konventix\Registration\Events\RegistrationOwnershipAnnounced` is this
 * module's second sanctioned import from Registration's `Events\`
 * namespace (ADR-0004), alongside `RegistrationConfirmed` and
 * `RegistrationAmended`; `tests/RegistrationCommerceImportBoundaryTest.php`
 * already allows any class from that namespace.
 */
final class FillBuyerOnRegistrationOwnershipAnnounced
{
    public function handle(RegistrationOwnershipAnnounced $event): void
    {
        Admission::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('registration_id', $event->registrationId)
            ->whereNull('buyer_user_id')
            ->update(['buyer_user_id' => $event->registrantUserId]);
    }
}
```
Modify `app-modules/admission/src/Providers/AdmissionServiceProvider.php`. Before L10 (`use Konventix\Admission\Listeners\IssueAdmissionsOnRegistrationAmended;`) insert:
```php
use Konventix\Admission\Listeners\FillBuyerOnRegistrationOwnershipAnnounced;
```
After L34 (`use Konventix\Registration\Events\RegistrationConfirmed;`) insert:
```php
use Konventix\Registration\Events\RegistrationOwnershipAnnounced;
```
Inside `registerListeners()`, after the line Task 13 added last (`Event::listen(VenueUnassigned::class, [ProjectEventSummary::class, 'handleVenueUnassigned']);`, now at L143), before the closing `}`, insert:
```php

        // design doc §13, 2026-09-11 amendment "a registration-derived
        // admission is anonymous today": backfills `buyer_user_id` on
        // admissions that predate it. Only ever null -> value, never
        // overwrites a set one (FillBuyerOnRegistrationOwnershipAnnounced's
        // own whereNull guard) — this is the only listener this narrow
        // event may ever have (see the event's own docblock).
        Event::listen(
            RegistrationOwnershipAnnounced::class,
            FillBuyerOnRegistrationOwnershipAnnounced::class,
        );
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/BackfillBuyerOnRegistrationOwnershipAnnouncedTest.php` — Expected: PASS, 4 passed.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/admission/TODO.md`: note `FillBuyerOnRegistrationOwnershipAnnounced` closes point 5's backfill chain, and that it is (and must remain) the sole listener on `RegistrationOwnershipAnnounced`.
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Listeners/FillBuyerOnRegistrationOwnershipAnnounced.php \
        app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/tests/BackfillBuyerOnRegistrationOwnershipAnnouncedTest.php \
        app-modules/admission/TODO.md
git commit -m "$(cat <<'EOF'
Listen to RegistrationOwnershipAnnounced to backfill admissions.buyer_user_id on rows that predate it, never overwriting a set one (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```

---

### Task 18: Registration's event summary gains times and a venue

**Files:**
- Create: `app-modules/registration/database/migrations/2026_09_11_040000_add_times_and_venue_to_registration_event_summaries_table.php`
- Modify: `app-modules/registration/src/Models/EventSummary.php` (docblock L39–48, `$fillable` L65, `casts()` L70–76)
- Modify: `app-modules/registration/src/Listeners/ProjectEventSummary.php` (full-file rewrite; see below)
- Modify: `app-modules/registration/src/Providers/RegistrationServiceProvider.php` (insert imports after L25, extend `registerListeners()` after L169)
- Test: `app-modules/registration/tests/EventSummaryProjectionTest.php`

**Interfaces:**
- Consumes: `Konventix\Event\Events\EventCreated`, `EventDetailsUpdated`, `EventRescheduled`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` (same six as Tasks 12–13, minus the create-on-reschedule need — see below)
- Produces: `registration_event_summaries.{starts_at, ends_at, timezone, venue_label, venue_id}`; `Konventix\Registration\Models\EventSummary` widened accordingly. This is what the (out-of-section) confirmation-mail calendar attachment reads through `Konventix\Platform\Calendar\CalendarFile`, without Registration ever importing Event.

Read `app-modules/registration/src/Listeners/ProjectEventSummary.php` and `app-modules/registration/src/Models/EventSummary.php` in full again before editing — this task extends the exact class Tasks 12–13 mirrored for Admission, and the design's instruction is explicit: "the same patch-not-replace care as point 2." The mechanism is identical to Task 13's (`array_filter` drops omitted fields, so `handleDetailsUpdated()` cannot erase a schedule and `handleRescheduled()` cannot erase a name), but there is one deliberate asymmetry, explained in this section's intro and restated in the code's own docblock: `handleRescheduled()` here **only ever patches an existing row — it never creates one.** Registration is a Core module (`$gateable = false`), so unlike Admission's brand-new table, `registration_event_summaries` has had a row for every event since 2026-08-17, written by `handleCreated()` at the moment the event itself was created; a reschedule, live or replayed, can never outrun it.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Registration\Models\EventSummary;

/**
 * `registration_event_summaries`' schedule and primary-venue columns
 * (design doc §13, 2026-09-11 amendment "the calendar builder moves to
 * Platform, and two modules gain an event read model"): the confirmation
 * mail's calendar attachment needs them, built through
 * `Konventix\Platform\Calendar\CalendarFile` without Registration ever
 * importing Event (ADR-0004).
 */
function registrationEventCreatedEvent(
    string $eventId,
    string $brandId,
    string $name = 'SXLan 2026',
    string $slug = 'sxlan-2026',
    string $startsAt = '2027-06-15 10:00:00',
    string $endsAt = '2027-06-17 18:00:00',
    string $timezone = 'Europe/Berlin',
): EventCreated {
    return new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: $slug,
        name: $name,
        startsAt: $startsAt,
        endsAt: $endsAt,
        timezone: $timezone,
        predecessorEventId: null,
    );
}

it('projects the schedule from EventCreated alongside the name and slug', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(registrationEventCreatedEvent($eventId, $brand->id));

    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();

    expect($summary->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00')
        ->and($summary->ends_at->format('Y-m-d H:i:s'))->toBe('2027-06-17 18:00:00')
        ->and($summary->timezone)->toBe('Europe/Berlin')
        ->and($summary->venue_label)->toBeNull();
});

it('a details update does not erase the times, and a reschedule does not erase the name', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id, name: 'Original Name'));

    event(new EventDetailsUpdated(eventId: $eventId, brandId: $brand->id, slug: 'renamed', name: 'Renamed'));

    $afterDetails = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($afterDetails->name)->toBe('Renamed')
        ->and($afterDetails->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');

    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    $afterReschedule = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($afterReschedule->name)->toBe('Renamed')
        ->and($afterReschedule->starts_at->format('Y-m-d H:i:s'))->toBe('2027-07-01 09:00:00');
});

it('a reschedule for an event with no summary row yet is a safe no-op', function (): void {
    $brand = $this->withBrand();

    event(new EventRescheduled(
        eventId: (string) Str::ulid(),
        startsAt: '2027-07-01 09:00:00',
        endsAt: '2027-07-03 20:00:00',
        timezone: 'Europe/Berlin',
        brandId: $brand->id,
    ));

    expect(EventSummary::count())->toBe(0);
});

it('sets the venue label from the primary venue only, and clears it on unassign', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $venueId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id));

    event(new VenueAssigned(eventId: $eventId, venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Side Room', city: 'Hamburg', isPrimary: false));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();

    event(new VenueAssigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum', city: 'Hamburg', isPrimary: true));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum, Hamburg');

    event(new VenueUpdated(venueId: $venueId, brandId: $brand->id, name: 'Congress Centrum West', city: 'Hamburg', countryCode: 'DE'));
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBe('Congress Centrum West, Hamburg');

    event(new VenueUnassigned(eventId: $eventId, venueId: $venueId, brandId: $brand->id));
    $summary = EventSummary::where('event_id', $eventId)->firstOrFail();
    expect($summary->venue_label)->toBeNull()
        ->and($summary->venue_id)->toBeNull();
});

it('an event with no venue leaves the label null and a stray venue event for another event breaks nothing', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    event(registrationEventCreatedEvent($eventId, $brand->id));

    event(new VenueUpdated(venueId: (string) Str::ulid(), brandId: $brand->id, name: 'Unrelated', city: 'Berlin', countryCode: 'DE'));
    event(new VenueUnassigned(eventId: (string) Str::ulid(), venueId: (string) Str::ulid(), brandId: $brand->id));

    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->venue_label)->toBeNull();
});

it('converges on replay: firing EventCreated twice for the same event leaves one row with the latest schedule', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(registrationEventCreatedEvent($eventId, $brand->id, startsAt: '2027-06-01 09:00:00', endsAt: '2027-06-01 18:00:00'));
    event(registrationEventCreatedEvent($eventId, $brand->id, startsAt: '2027-06-15 10:00:00', endsAt: '2027-06-17 18:00:00'));

    expect(EventSummary::where('event_id', $eventId)->count())->toBe(1);
    expect(EventSummary::where('event_id', $eventId)->firstOrFail()->starts_at->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/EventSummaryProjectionTest.php`
Expected: FAIL with `Illuminate\Database\Eloquent\MissingAttributeException: The attribute [starts_at] either does not exist or was not retrieved for model [Konventix\Registration\Models\EventSummary].`
- [ ] **Step 3: Implement**
```php
<?php
// app-modules/registration/database/migrations/2026_09_11_040000_add_times_and_venue_to_registration_event_summaries_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `registration_event_summaries` gains an event's schedule and primary
     * venue (design doc §13, 2026-09-11 amendment "the calendar builder
     * moves to Platform, and two modules gain an event read model").
     * Registration builds the confirmation mail's calendar attachment
     * through `Konventix\Platform\Calendar\CalendarFile` — scalars in,
     * bytes out — and may not read Event's models to supply them
     * (ADR-0004), so this table, already Registration's own copy of an
     * event's name and slug, grows the rest of what that call needs.
     *
     * All four display columns are nullable: existing rows have none of
     * them until `event:replay-details` backfills them. `venue_id` is
     * bookkeeping, not itself printed anywhere: it is what lets
     * `Listeners\ProjectEventSummary::handleVenueUpdated()` find this row
     * again when the venue is renamed, and `handleVenueUnassigned()` tell
     * its own primary venue apart from any other.
     */
    public function up(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('venue_label')->nullable();
            $table->ulid('venue_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('registration_event_summaries', function (Blueprint $table): void {
            $table->dropColumn(['starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id']);
        });
    }
};
```
Modify `app-modules/registration/src/Models/EventSummary.php`. In the docblock, after `@property string|null $visibility` (L44) insert:
```php
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property string|null $venue_label
 * @property string|null $venue_id
```
Replace L65:
```php
    protected $fillable = ['event_id', 'name', 'slug', 'visibility', 'public_since', 'archived_at'];
```
with:
```php
    protected $fillable = ['event_id', 'name', 'slug', 'visibility', 'starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id', 'public_since', 'archived_at'];
```
Replace L70–76:
```php
    protected function casts(): array
    {
        return [
            'public_since' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
```
with:
```php
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'public_since' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
```
```php
<?php
// app-modules/registration/src/Listeners/ProjectEventSummary.php — full resulting file

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
use Konventix\Registration\Models\EventSummary;

/**
 * Keeps Registration's copy of which events exist and what they are called
 * (ADR-0004: this module may not read Event's models, so it projects what it
 * needs from Event's contract events).
 *
 * Creation and detail updates share one path because both payloads carry the
 * complete current name and slug — a projection that replaces rather than
 * patches converges on replay.
 *
 * Not gated on anything: Registration is a Core module and always active, and
 * a projection must stay current regardless of what consumes it later.
 *
 * `public_since` is `Event::isVisibleOnLanding()`'s rule — "has left
 * `Draft`" — and `visibility` is the second half a *listing* surface owes,
 * so that `public_since` + `visibility = public` is
 * `Event::scopeListedPublicly()` (branch review blocker 2). Both are held in
 * the only form ADR-0004 allows this module: scalars off the contract
 * events. Both
 * transitions out of `Draft` set it, and nothing clears it:
 * `EventUnpublished` is deliberately *not* projected, because
 * `UnpublishEvent` returns the event to `Announced` rather than to `Draft`,
 * so its landing page keeps resolving and keeps selling the very same
 * registration types. Clearing the column there made `/shop` and the landing
 * page disagree about one event (#240 re-review N3). `EventArchived` is the
 * transition that genuinely takes offers off both, and it has its own
 * column.
 *
 * `starts_at`/`ends_at`/`timezone` and `venue_label` (design doc §13,
 * 2026-09-11 amendment) feed the confirmation mail's calendar attachment.
 * `EventCreated` carries times and a timezone alongside name/slug, so
 * `handleCreated()` sets everything on one row through the same `project()`
 * helper. `EventRescheduled` carries times but no name or slug — the
 * opposite gap from `EventDetailsUpdated` — so `handleRescheduled()`
 * patches only those three columns and never touches `name`/`slug`, the
 * same "patch, never replace" care `handleDetailsUpdated()` already took
 * for `visibility`. Unlike Resources' `ProjectEventWindow::handleRescheduled()`
 * or this section's own `Konventix\Admission\Listeners\ProjectEventSummary::handleRescheduled()`,
 * this one never *creates* a row: Registration is a Core module
 * (`$gateable = false`), so — unlike Resources, which is Pennant-gated and
 * may be switched on for a brand mid-event, and unlike Admission's table,
 * which is brand new — every event already has a row here from
 * `handleCreated()` by the time any reschedule, real or replayed, can reach
 * it. A reschedule (or a venue event) for an event with no row yet is
 * therefore a safe no-op, not a creation path.
 */
final class ProjectEventSummary
{
    public function handleCreated(EventCreated $event): void
    {
        $this->project(
            eventId: $event->eventId,
            brandId: $event->brandId,
            name: $event->name,
            slug: $event->slug,
            visibility: $event->visibility,
            startsAt: $event->startsAt,
            endsAt: $event->endsAt,
            timezone: $event->timezone,
        );
    }

    public function handleDetailsUpdated(EventDetailsUpdated $event): void
    {
        $this->project($event->eventId, $event->brandId, $event->name, $event->slug, $event->visibility);
    }

    /**
     * See the class docblock: patches the schedule on a row that already
     * exists, and never creates one.
     */
    public function handleRescheduled(EventRescheduled $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('event_id', $event->eventId)
            ->update([
                'starts_at' => $event->startsAt,
                'ends_at' => $event->endsAt,
                'timezone' => $event->timezone,
            ]);
    }

    /**
     * Only the primary venue (design §6.1: the calendar `LOCATION` names
     * it) — a secondary assignment is a no-op. `venue_id` lets
     * `handleVenueUpdated()`/`handleVenueUnassigned()` find this row again.
     */
    public function handleVenueAssigned(VenueAssigned $event): void
    {
        if (! $event->isPrimary) {
            return;
        }

        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update([
                'venue_id' => $event->venueId,
                'venue_label' => "{$event->name}, {$event->city}",
            ]);
    }

    /**
     * `VenueUpdated` carries no `eventId` — the same venue may be the
     * primary venue of more than one event's summary — so every row
     * pointing at it is patched.
     */
    public function handleVenueUpdated(VenueUpdated $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_label' => "{$event->name}, {$event->city}"]);
    }

    public function handleVenueUnassigned(VenueUnassigned $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->where('venue_id', $event->venueId)
            ->update(['venue_id' => null, 'venue_label' => null]);
    }

    /**
     * The event became publicly visible (#240). Both transitions out of
     * `Draft` count: `Event::isVisibleOnLanding()` is `state !== Draft`, so
     * an announced event's landing page resolves and its offers are real —
     * projecting only `EventPublished` would drop them off `/shop` for the
     * whole announced phase.
     *
     * Idempotent on replay: the *first* moment it went public is the fact,
     * so a second transition does not move the timestamp.
     */
    public function handleAnnounced(EventAnnounced $event): void
    {
        $this->markPublic($event->eventId, $event->brandId, $event->announcedAt);
    }

    public function handlePublished(EventPublished $event): void
    {
        $this->markPublic($event->eventId, $event->brandId, $event->publishedAt);
    }

    /**
     * An archived event keeps its row — existing registration types still
     * need a name to display — but the type editor stops offering it.
     */
    public function handleArchived(EventArchived $event): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->update(['archived_at' => $event->archivedAt]);
    }

    private function markPublic(string $eventId, string $brandId, string $since): void
    {
        EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereNull('public_since')
            ->update(['public_since' => $since]);
    }

    /**
     * `$visibility` is nullable because it was added to the contract events
     * after they existed (branch review blocker 2): a payload without it
     * leaves whatever is already projected alone rather than blanking the
     * column, and a row that has never been told stays null — which the
     * shop gate reads as "not listable", the safe direction. `$startsAt`/
     * `$endsAt`/`$timezone` are nullable for the same reason, one level
     * later (design §13): `handleDetailsUpdated()` omits them so a details
     * update cannot blank a schedule already projected by `handleCreated()`.
     */
    private function project(
        string $eventId,
        string $brandId,
        string $name,
        string $slug,
        ?string $visibility = null,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?string $timezone = null,
    ): void {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first() ?? new EventSummary(['event_id' => $eventId]);

        // brand_id from the payload, not ambient context (ADR-0010).
        $summary->forceFill(array_filter([
            'brand_id' => $brandId,
            'name' => $name,
            'slug' => $slug,
            'visibility' => $visibility,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $timezone,
        ], fn (?string $value): bool => $value !== null))->save();
    }
}
```
Modify `app-modules/registration/src/Providers/RegistrationServiceProvider.php`. After L25 (`use Konventix\Event\Events\EventPublished;`) insert:
```php
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Events\VenueUnassigned;
use Konventix\Event\Events\VenueUpdated;
```
Inside `registerListeners()`, after L169 (`Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);`), before L170 (`Event::listen(EventArchived::class, [ProjectEventSummary::class, 'handleArchived']);`), insert:
```php
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);
        Event::listen(VenueAssigned::class, [ProjectEventSummary::class, 'handleVenueAssigned']);
        Event::listen(VenueUpdated::class, [ProjectEventSummary::class, 'handleVenueUpdated']);
        Event::listen(VenueUnassigned::class, [ProjectEventSummary::class, 'handleVenueUnassigned']);
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/EventSummaryProjectionTest.php` — Expected: PASS, 6 passed. Then also re-run `app-modules/registration/tests/PublicOffersTest.php` and `app-modules/registration/tests/SalesWindowTest.php` (both construct `EventSummary` rows directly and exercise `ProjectEventSummary`'s other handlers) to confirm this task's changes to `project()` and the class did not disturb them.
- [ ] **Step 5: Verify and commit**
Also update `app-modules/registration/TODO.md`: note `registration_event_summaries` now carries the schedule and the primary venue, projected with the same patch-not-replace care as Admission's table, feeding the (later) calendar attachment via `Konventix\Platform\Calendar\CalendarFile`. Also update `app-modules/registration/SPEC.md`'s "Owns"/"Consumes" sections to list the widened `registration_event_summaries` columns and the four newly-consumed Event contract events (`EventRescheduled`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned`).
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- registration`
```bash
git add app-modules/registration/database/migrations/2026_09_11_040000_add_times_and_venue_to_registration_event_summaries_table.php \
        app-modules/registration/src/Models/EventSummary.php \
        app-modules/registration/src/Listeners/ProjectEventSummary.php \
        app-modules/registration/src/Providers/RegistrationServiceProvider.php \
        app-modules/registration/tests/EventSummaryProjectionTest.php \
        app-modules/registration/TODO.md \
        app-modules/registration/SPEC.md
git commit -m "$(cat <<'EOF'
Widen registration_event_summaries with an event's schedule and primary venue, patched without erasing the name, for the confirmation mail's calendar attachment (#72)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HeNdKD6828NfAdPMNCb7tS
EOF
)"
```
## Part — The ticket document (§4)

Tasks 19–22 build `Konventix\Admission\Documents\TicketDocumentRenderer`: one
A4 portrait page per admission, event band / holder band / footer band,
watermarked by Platform's `DocumentWatermark` service. Task 19 also creates
`app-modules/admission/tests/Helpers.php`, the shared fixture file every
later section of this plan appends to. No route, controller, Vue page or
slot contribution lands here — that is the next section's work, and neither
`IssueAdmissionToken` nor the entrance code is touched.

Every task below assumes `Konventix\Admission\Models\EventSummary`,
`Konventix\Admission\Models\Person`, `Konventix\Platform\Documents\DocumentWatermark`
and `IssueAdmissionToken::current()`/`::rotate()` already exist, built by
earlier tasks in this plan.

---

### Task 19: Renderer skeleton, shared test fixtures, the event band

**Files:**
- Create: `app-modules/admission/tests/Helpers.php`
- Create: `app-modules/admission/tests/TicketDocumentTest.php`
- Create: `app-modules/admission/src/Documents/TicketDocumentRenderer.php`
- Create: `app-modules/admission/resources/views/tickets/document.blade.php`
- Modify: `app-modules/admission/SPEC.md`
- Modify: `app-modules/admission/TODO.md`

**Interfaces:**
- Consumes: `Konventix\Platform\Media\MediaLibrary::dataUriFor(string $mediaId, ?string $variant = null): ?string`; `Konventix\Platform\Operators\OperatorDateTimeFormat::dateTime(CarbonInterface $at, ?string $zone = null): string`; `Konventix\Admission\Models\EventSummary` (`event_id`, `brand_id`, `name`, `slug`, `starts_at`, `ends_at`, `timezone`, `venue_label`); `Konventix\Platform\Models\Brand` (`logo_media_id`, `name`)
- Produces: `Konventix\Admission\Documents\TicketDocumentRenderer::render(\Illuminate\Support\Collection $admissions): string` (event band only at the end of this task; every later task in this section extends it without changing this signature); view `admission::tickets.document`; test helpers `ticketOperatorBrand(array $operatorAttributes = [], array $brandAttributes = []): Brand`, `ticketEventSummary(Brand $brand, string $eventId, array $overrides = []): EventSummary`, `ticketPerson(array $overrides = []): Person`, `ticketAdmissionFor(string $eventId, ?Person $holder = null, array $overrides = []): Admission`, `ticketSigningKey(Brand $brand): EntranceSigningKey`

- [ ] **Step 1: Write the failing test**

`app-modules/admission/tests/Helpers.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Shared fixtures for this feature's admission tests (#72). Every function
 * here is process-global (.ai/rules/tests.md) and prefixed `ticket` — the
 * admission module already owns `badgeTestOperator()`, file-local to
 * BadgeDocumentTest.php, so this prefix keeps these names apart from that
 * one and from every other module's Helpers.php the suite loads into the
 * same worker.
 *
 * None of these set the ambient brand context — call `$this->withBrand()`
 * with the `Brand` a helper hands back, the way every other module's
 * Helpers.php does; a plain `function` cannot reach the protected
 * `TestCase::withBrand()` itself.
 */

/**
 * A brand tied to a specific operator, for tests that need to control what
 * the ticket footer's operator line and the forensic watermark's legal-name
 * line say. `$this->withBrand()` alone would work too, but its operator is
 * whichever random one `BrandFactory` reuses or mints — not something a
 * ticket test can assert against on purpose.
 */
function ticketOperatorBrand(array $operatorAttributes = [], array $brandAttributes = []): Brand
{
    $operator = Operator::factory()->create($operatorAttributes);

    return Brand::factory()->for($operator)->create($brandAttributes);
}

/**
 * Admission's own read model of one event (§13 amendment "planning: the
 * calendar builder moves to Platform..."), keyed by `[brand_id, event_id]`
 * the way `registration_event_summaries` is. No factory ships with the
 * model — it exists only through its own projector — so tests build the
 * row directly, the same way Registration's `Registrant` and Messaging's
 * `Recipient` rows are built by the modules that read Identity's
 * projection.
 */
function ticketEventSummary(Brand $brand, string $eventId, array $overrides = []): EventSummary
{
    return EventSummary::query()->create(array_merge([
        'brand_id' => $brand->id,
        'event_id' => $eventId,
        'name' => 'Kon Fest 2026',
        'slug' => 'kon-fest-2026',
        'starts_at' => Carbon::parse('2026-10-16 10:00:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2026-10-18 18:00:00', 'Europe/Berlin'),
        'timezone' => 'Europe/Berlin',
        'venue_label' => 'Halle 3, Musterstadt',
    ], $overrides));
}

/**
 * A holder, projected the way Admission's own people read model is (§13
 * amendment "the holder-name fallback drops the billing recipient"). No
 * factory ships with the model, matching `Registrant`/`Recipient`. The
 * email is derived from the id rather than fixed, so a test creating
 * several people never collides on a possible unique index.
 */
function ticketPerson(array $overrides = []): Person
{
    $id = (string) Str::ulid();

    return Person::query()->create(array_merge([
        'id' => $id,
        'display_name' => 'Erika Musterfrau',
        'email' => sprintf('holder+%s@example.test', strtolower($id)),
    ], $overrides));
}

/**
 * One admission for the given event, holding either a specific `$holder`
 * or none at all — pass `null` for the unclaimed case the badge already
 * prints as "Unassigned" and the ticket must too.
 */
function ticketAdmissionFor(string $eventId, ?Person $holder = null, array $overrides = []): Admission
{
    return Admission::factory()->create(array_merge([
        'event_id' => $eventId,
        'holder_user_id' => $holder?->id,
    ], $overrides));
}

/**
 * An active Ed25519 signing key for the brand (ADR-0033) — every render
 * that reaches `IssueAdmissionToken::current()` needs one, or minting
 * fails loudly with "run admission:rotate-signing-key first".
 */
function ticketSigningKey(Brand $brand): EntranceSigningKey
{
    return app(RotateEntranceSigningKey::class)->handle($brand->id);
}
```

`app-modules/admission/tests/TicketDocumentTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Models\Admission;

/**
 * Ticket rendering (§4 of docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md).
 * Asserted on structure and magic bytes, never byte-compared, mirroring
 * `BadgeDocumentTest.php` and `InvoiceDocumentTest.php` — a PDF fixture
 * comparison would break on any dompdf or font change, and this suite
 * cannot inspect text inside the rendered PDF at all (font subsetting and
 * dompdf's own compression make that unreliable), so every assertion below
 * reads magic bytes, length, or the database, never PDF content.
 */
it('renders a real PDF for one admission', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    $bytes = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('renders one document per collection, longer for three admissions than for one', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);

    $single = ticketAdmissionFor($eventId, ticketPerson());
    $singleBytes = app(TicketDocumentRenderer::class)->render(collect([$single]));

    $three = Admission::factory()->count(3)->create(['event_id' => $eventId]);
    $threeBytes = app(TicketDocumentRenderer::class)->render($three);

    expect(str_starts_with($threeBytes, '%PDF'))->toBeTrue()
        ->and(strlen($threeBytes))->toBeGreaterThan(strlen($singleBytes));
});

it('renders when the brand has no logo', function (): void {
    // BrandFactory leaves `logo_media_id` null by default — this is the
    // common case, not a special one.
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('renders an admission whose event has no summary yet', function (): void {
    $this->withBrand(ticketOperatorBrand());

    // No ticketEventSummary() call: the projector has not caught up, or
    // never will for this event.
    $admission = ticketAdmissionFor((string) Str::ulid(), ticketPerson());

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('renders a staff admission with no registration at all', function (): void {
    // registration_id is nullable (§4.1) and a staff admission has none —
    // the page must still render.
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson(), [
        'registration_id' => null,
        'role' => 'crew',
    ]);

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('stores no PDF on disk', function (): void {
    // Rendered fresh on every request, like the badge and unlike the
    // invoice (§4.4) — no retention duty, no GDPR surface from a stored copy.
    Storage::fake('media');
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(Storage::disk('media')->allFiles())->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDocumentTest.php`
Expected: FAIL — `Error: Class "Konventix\Admission\Documents\TicketDocumentRenderer" not found` in `TicketDocumentTest.php`.

- [ ] **Step 3: Implement**

`app-modules/admission/src/Documents/TicketDocumentRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand is
 * resolved once from the first admission rather than per page.
 *
 * Brand is read through a plain query, never `$admission->brand`:
 * `Model::shouldBeStrict()` is on in non-production and treats *any*
 * unloaded relation access as a violation, not only a repeated one inside
 * a loop — exactly what `InvoiceDocumentRenderer` already routes around
 * with `Order::withoutBrandScope()->whereKey(...)` instead of
 * `$invoice->order`.
 *
 * This task lays down the page loop and the event band only. Task 20 adds
 * the holder band and the QR, Task 21 the footer band and the first two
 * watermark layers, Task 22 the TESTSYSTEM layer — none of them change
 * this class's constructor or `render()` signature, only extend what
 * `pageDataFor()` builds and what the view shows.
 */
final class TicketDocumentRenderer
{
    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->find($brandId);

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $eventSummaries->get($admission->event_id),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * One query for every event this run needs, not one per admission.
     * `EventSummary` is Admission's own read model (§13 amendment "the
     * calendar builder moves to Platform...") — this module may not read
     * Event's tables (ADR-0004).
     *
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, EventSummary> keyed by event_id
     */
    private function eventSummariesFor(Collection $admissions, ?string $brandId): Collection
    {
        if ($brandId === null) {
            return collect();
        }

        // Explicit brand, not ambient context (ADR-0010 escape hatch): a
        // ticket can render from a queue worker or an operator console
        // with no brand resolved from the request.
        return EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->get()
            ->keyBy('event_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?EventSummary $event): array
    {
        return [
            'brandLogo' => $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id),
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
        ];
    }

    /**
     * The event's own wall clock, in the event's own timezone (§4.2:
     * "local start and end... with the timezone named") — never converted
     * to the operator's clock, which is what `OperatorDateTimeFormat`
     * would do by default for an instant with no zone given.
     */
    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at, $event->timezone),
            $this->dates->dateTime($event->ends_at, $event->timezone),
            $event->timezone,
        );
    }
}
```

`app-modules/admission/resources/views/tickets/document.blade.php`:

```blade
{{--
    Ticket document (#72 §4), rendered by
    `Konventix\Admission\Documents\TicketDocumentRenderer` through dompdf.

    Same constraints as the badge and the invoice: block layout, inline
    CSS, no flexbox or grid, and NO external assets — every image arrives
    as a `data:` URI because dompdf ships with remote fetching disabled.
    Semantic theming (ADR-0008) does not apply here for the same reason it
    does not on the badge or the invoice: this is rendered server-side with
    no stylesheet pipeline, on a fixed layout (out of scope: "an editable
    ticket template").

    One `.ticket-page` per admission, `page-break-before` on every page
    after the first so dompdf never adds a trailing blank page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
        .ticket-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
        }
        .event-band {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 90mm;
            padding: 14mm 16mm;
            box-sizing: border-box;
            border-bottom: 0.75pt solid #d1d5db;
        }
        .event-band .logo { max-height: 24mm; max-width: 60mm; margin-bottom: 8mm; }
        .event-band .event-name { font-size: 20pt; font-weight: bold; margin: 0 0 4mm; }
        .event-band .event-when { font-size: 11pt; color: #374151; margin: 0 0 2mm; }
        .event-band .venue { font-size: 11pt; color: #374151; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="event-band">
            @if ($page['brandLogo'] !== null)
                <img class="logo" src="{{ $page['brandLogo'] }}" alt="">
            @endif

            <div class="event-name">{{ $page['eventName'] ?? __('Event details unavailable') }}</div>

            @if ($page['eventWhen'] !== null)
                <div class="event-when">{{ $page['eventWhen'] }}</div>
            @endif

            @if ($page['venueLabel'] !== null)
                <div class="venue">{{ $page['venueLabel'] }}</div>
            @endif
        </div>
    </div>
@endforeach
</body>
</html>
```

`app-modules/admission/SPEC.md` — insert this bullet into `## Owns`, directly after the existing **Ticket tokens** bullet:

```markdown
- **The ticket document** — one A4 PDF page per admission
  (`Documents\TicketDocumentRenderer`, mirroring `BadgeDocumentRenderer`):
  an event band, a holder band with the QR, and a footer band naming the
  operator and where the full purchase conditions live. Rendered fresh on
  every request and stored nowhere, like the badge. Watermarked by
  Platform's shared `DocumentWatermark` service — a brand mark, a forensic
  repeat seeded from the admission id, and a TESTSYSTEM band off
  production — which Admission is the first module to call.
```

`app-modules/admission/TODO.md` — insert as the first bullet under `## Done`:

```markdown
- [x] Ticket PDF, part 1 — `Documents\TicketDocumentRenderer` renders one
      A4 page per admission with the event band (brand logo, name, local
      wall clock with the timezone named, venue), mirroring
      `BadgeDocumentRenderer` (#72 §4). The holder band, the footer band
      and the watermark layers land next.
```

- [ ] **Step 4: Run it to verify it passes**

Run the same command as Step 2. Expected: PASS, all 6 examples green.

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

```bash
git add app-modules/admission/tests/Helpers.php \
        app-modules/admission/tests/TicketDocumentTest.php \
        app-modules/admission/src/Documents/TicketDocumentRenderer.php \
        app-modules/admission/resources/views/tickets/document.blade.php \
        app-modules/admission/SPEC.md \
        app-modules/admission/TODO.md
git commit -m "Render the ticket PDF's event band, one A4 page per admission (#72)"
```

---

### Task 20: The holder band — QR, holder name, role, valid days, door code

**Files:**
- Modify: `app-modules/admission/src/Documents/TicketDocumentRenderer.php`
- Modify: `app-modules/admission/resources/views/tickets/document.blade.php`
- Modify: `app-modules/admission/tests/TicketDocumentTest.php`
- Modify: `app-modules/admission/TODO.md`

**Interfaces:**
- Consumes: `Konventix\Admission\Actions\IssueAdmissionToken::current(Admission $admission, array $dayIds = []): string`; `Konventix\Admission\Models\Person` (`id`, `display_name`, `email`)
- Produces: `TicketDocumentRenderer` gains a QR image, a holder name (falling back to "Unassigned"), a role, a valid-days range and a door-typeable short admission code on every page; no change to `render()`'s public signature

- [ ] **Step 1: Write the failing test**

Append to `app-modules/admission/tests/TicketDocumentTest.php` (add these `use` statements to the top of the file and these two examples at the end):

```php
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Models\Admission;
use Mockery;
use Mockery\MockInterface;
```

```php
it('asks IssueAdmissionToken for the admission\'s current token, never a fresh one', function (): void {
    // §5: `current()` returns the stored nonce and mints one only if none
    // exists — `rotate()` is a write path this renderer must never call.
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    $this->mock(IssueAdmissionToken::class, function (MockInterface $mock) use ($admission): void {
        $mock->shouldReceive('current')
            ->once()
            ->with(Mockery::on(fn (Admission $given): bool => $given->id === $admission->id))
            ->andReturn('KVX1FAKETOKENFORRENDERINGTEST');
    });

    $bytes = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});

it('renders the same token across two requests', function (): void {
    // The regression test for §5: a downloaded ticket must stay valid, so
    // a second render must not mint a second nonce.
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    // Exercises the no-holder path too: `holder_user_id` stays null.
    $admission = ticketAdmissionFor($eventId, null);

    app(TicketDocumentRenderer::class)->render(collect([$admission]));
    $first = $admission->fresh()->token_nonce_hash;

    app(TicketDocumentRenderer::class)->render(collect([$admission]));
    $second = $admission->fresh()->token_nonce_hash;

    expect($first)->not->toBeNull()
        ->and($second)->toBe($first);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDocumentTest.php`
Expected: FAIL — the mocked `IssueAdmissionToken::current()` expectation is never met (`Mockery\Exception\InvalidCountException: Method current(<Any Arguments>) from Mockery_...IssueAdmissionToken should be called exactly 1 times but called 0 times`), and separately `Failed asserting that null is not null` on `$admission->fresh()->token_nonce_hash` — the renderer never touches a token yet.

- [ ] **Step 3: Implement**

`app-modules/admission/src/Documents/TicketDocumentRenderer.php` (full replacement):

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand is
 * resolved once from the first admission rather than per page.
 *
 * Brand is read through a plain query, never `$admission->brand` (see
 * Task 19's note on `Model::shouldBeStrict()`).
 *
 * This task adds the holder band: the QR built from
 * `IssueAdmissionToken::current()` — never `rotate()`, which is a write
 * path only a claim or a reassignment may call — the holder's name or
 * "Unassigned", the role, the valid-days range and a door-typeable short
 * code. Task 21 adds the footer band and the first two watermark layers,
 * Task 22 the TESTSYSTEM layer.
 */
final class TicketDocumentRenderer
{
    /**
     * The QR's nominal size (§4.2). Same technique as
     * `BadgeDocumentRenderer::qrDataUri()` — an inline SVG needs no
     * imagick or gd and dompdf renders it natively — at the ticket's own
     * size rather than the badge's smaller one.
     */
    private const int QR_SIZE_PX = 320;

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
        private readonly IssueAdmissionToken $issueAdmissionToken,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->find($brandId);

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);
        $people = $this->peopleFor($admissions);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $eventSummaries->get($admission->event_id),
            $admission->holder_user_id === null ? null : $people->get($admission->holder_user_id),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, EventSummary> keyed by event_id
     */
    private function eventSummariesFor(Collection $admissions, ?string $brandId): Collection
    {
        if ($brandId === null) {
            return collect();
        }

        return EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->get()
            ->keyBy('event_id');
    }

    /**
     * One query for every holder this run needs. `Person` is Admission's
     * own projection of Identity's account events (§13 amendment "the
     * holder-name fallback drops the billing recipient") — this module may
     * not read Identity's `users` table (ADR-0004).
     *
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, Person>
     */
    private function peopleFor(Collection $admissions): Collection
    {
        $holderIds = $admissions->pluck('holder_user_id')->filter()->unique()->values();

        if ($holderIds->isEmpty()) {
            return collect();
        }

        return Person::query()->whereIn('id', $holderIds)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?EventSummary $event, ?Person $holder): array
    {
        return [
            'brandLogo' => $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id),
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
            'holderName' => $holder?->display_name ?? 'Unassigned',
            'role' => str_replace('_', ' ', $admission->role),
            'validRange' => $this->validRangeFor($admission),
            'doorCode' => $this->doorCode($admission->id),
            'qr' => $this->qrDataUri($this->issueAdmissionToken->current($admission)),
        ];
    }

    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at, $event->timezone),
            $this->dates->dateTime($event->ends_at, $event->timezone),
            $event->timezone,
        );
    }

    /**
     * The days the token covers (§4.2). `valid_from`/`valid_until` null —
     * the common case — means the admission carries no window narrower
     * than the whole event, and the ticket says so in words rather than
     * printing an empty range.
     */
    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return 'All event days';
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }

    /**
     * A door-typeable fallback for when a scan fails and there is no other
     * way to look the holder up (mirrors the badge's `.ref` line,
     * ADR-0033) — not the full 26-character ULID printed there: its first
     * 10 characters are a shared, low-entropy timestamp, so the last 8 —
     * the random tail — carry the identifying bits, split into two groups
     * of four to read aloud at a door.
     */
    private function doorCode(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }

    /**
     * @see BadgeDocumentRenderer::qrDataUri() the same technique — SVG,
     * not raster: no imagick or gd, crisp at any print size, dompdf
     * renders it natively.
     */
    private function qrDataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE_PX, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
```

`app-modules/admission/resources/views/tickets/document.blade.php` (full replacement — the event band is unchanged, a holder band is added below it):

```blade
{{--
    Ticket document (#72 §4), rendered by
    `Konventix\Admission\Documents\TicketDocumentRenderer` through dompdf.

    Same constraints as the badge and the invoice: block layout, inline
    CSS, no flexbox or grid, and NO external assets — every image arrives
    as a `data:` URI because dompdf ships with remote fetching disabled.
    Semantic theming (ADR-0008) does not apply here, matching the badge and
    the invoice.

    One `.ticket-page` per admission, `page-break-before` on every page
    after the first so dompdf never adds a trailing blank page. Three
    absolutely-positioned bands stack down the page: event (0–90mm),
    holder (90–255mm), footer (255–297mm, added in Task 21).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
        .ticket-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
        }
        .event-band {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 90mm;
            padding: 14mm 16mm;
            box-sizing: border-box;
            border-bottom: 0.75pt solid #d1d5db;
        }
        .event-band .logo { max-height: 24mm; max-width: 60mm; margin-bottom: 8mm; }
        .event-band .event-name { font-size: 20pt; font-weight: bold; margin: 0 0 4mm; }
        .event-band .event-when { font-size: 11pt; color: #374151; margin: 0 0 2mm; }
        .event-band .venue { font-size: 11pt; color: #374151; }

        .holder-band {
            position: absolute;
            top: 90mm;
            left: 0;
            width: 100%;
            height: 165mm;
            padding: 12mm 16mm;
            box-sizing: border-box;
            text-align: center;
        }
        .qr-box {
            display: inline-block;
            background-color: #ffffff;
            padding: 10px;
            margin: 4mm 0 6mm;
        }
        .qr-box img { width: 320px; height: 320px; display: block; }
        .holder-name { font-size: 18pt; font-weight: bold; margin: 0 0 2mm; }
        .holder-name.unassigned { font-style: italic; font-weight: normal; color: #6b7280; }
        .role-badge {
            display: inline-block;
            margin: 2mm 0 6mm;
            padding: 2mm 6mm;
            border-radius: 10pt;
            font-size: 9pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffffff;
            background-color: #1f2937;
        }
        .valid-range { font-size: 10pt; color: #374151; margin-bottom: 2mm; }
        .door-code { font-size: 8pt; color: #9ca3af; letter-spacing: 0.06em; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="event-band">
            @if ($page['brandLogo'] !== null)
                <img class="logo" src="{{ $page['brandLogo'] }}" alt="">
            @endif

            <div class="event-name">{{ $page['eventName'] ?? __('Event details unavailable') }}</div>

            @if ($page['eventWhen'] !== null)
                <div class="event-when">{{ $page['eventWhen'] }}</div>
            @endif

            @if ($page['venueLabel'] !== null)
                <div class="venue">{{ $page['venueLabel'] }}</div>
            @endif
        </div>

        <div class="holder-band">
            <div class="qr-box"><img src="{{ $page['qr'] }}" alt=""></div>

            {{-- ADR-0032: an admission may legitimately have no holder yet;
                 the ticket prints an unclaimed placeholder the same way the
                 badge does rather than treating it as broken data. --}}
            @if ($page['holderName'] === 'Unassigned')
                <div class="holder-name unassigned">{{ __('Unassigned') }}</div>
            @else
                <div class="holder-name">{{ $page['holderName'] }}</div>
            @endif

            <div class="role-badge">{{ $page['role'] }}</div>
            <div class="valid-range">{{ $page['validRange'] }}</div>

            {{-- Short reference for the door to type in when a scan fails
                 (ADR-0033 accepts losing the name-based fallback offline). --}}
            <div class="door-code">{{ __('Door code') }}: {{ $page['doorCode'] }}</div>
        </div>
    </div>
@endforeach
</body>
</html>
```

- [ ] **Step 4: Run it to verify it passes**

Run the same command as Step 2. Expected: PASS, all 8 examples green.

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

`app-modules/admission/TODO.md` — insert as the first bullet under `## Done`:

```markdown
- [x] Ticket PDF, part 2 — the holder band: the QR built from
      `IssueAdmissionToken::current()` (never `rotate()`, so a
      re-download stays the same code, pinned by
      "renders the same token across two requests"), the holder's name or
      "Unassigned", the role, the valid-days range, and a door-typeable
      short code (#72 §4.2).
```

```bash
git add app-modules/admission/src/Documents/TicketDocumentRenderer.php \
        app-modules/admission/resources/views/tickets/document.blade.php \
        app-modules/admission/tests/TicketDocumentTest.php \
        app-modules/admission/TODO.md
git commit -m "Add the ticket PDF's holder band, QR and stable token (#72)"
```

---

### Task 21: The footer band and the first two watermark layers

**Files:**
- Modify: `app-modules/admission/src/Documents/TicketDocumentRenderer.php`
- Modify: `app-modules/admission/resources/views/tickets/document.blade.php`
- Modify: `app-modules/admission/tests/TicketDocumentTest.php`
- Modify: `app-modules/admission/TODO.md`

**Interfaces:**
- Consumes: `Konventix\Platform\Documents\DocumentWatermark::html(array $forensicLines, ?string $brandLogoDataUri, string $seed): string`; `Konventix\Platform\Models\Operator` (`legal_name`)
- Produces: `TicketDocumentRenderer` gains the footer band (operator legal name, brand name, ticket id, terms line) and the brand-mark + forensic-repeat watermark layers, stacked so the QR sits in an opaque box above them

- [ ] **Step 1: Write the failing test**

Append to `app-modules/admission/tests/TicketDocumentTest.php` (add this `use` statement and this example at the end):

```php
use Konventix\Platform\Documents\DocumentWatermark;
```

```php
it('hands the watermark service the forensic lines composed from the page, and the brand logo as its seed image', function (): void {
    // §4.3: holder name, event name, venue, operator legal name, role and
    // the admission id, in that order, composed here as plain strings —
    // "the service knows nothing about admissions".
    $brand = $this->withBrand(ticketOperatorBrand(['legal_name' => 'Sund-Xplosion e.V.']));

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId, [
        'name' => 'Kon Fest 2026',
        'venue_label' => 'Halle 3, Musterstadt',
    ]);
    $holder = ticketPerson(['display_name' => 'Erika Musterfrau']);
    $admission = ticketAdmissionFor($eventId, $holder, ['role' => 'exhibitor_crew']);

    $this->mock(DocumentWatermark::class, function (MockInterface $mock) use ($admission): void {
        $mock->shouldReceive('html')
            ->once()
            ->with(
                [
                    'Erika Musterfrau',
                    'Kon Fest 2026',
                    'Halle 3, Musterstadt',
                    'Sund-Xplosion e.V.',
                    'exhibitor crew',
                    $admission->id,
                ],
                null, // BrandFactory leaves logo_media_id null by default.
                $admission->id,
            )
            ->andReturn('');
        $mock->shouldReceive('testSystemBanner')->andReturn('');
    });

    $bytes = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDocumentTest.php`
Expected: FAIL — `Mockery\Exception\InvalidCountException: Method html(<Any Arguments>) from Mockery_...DocumentWatermark should be called exactly 1 times but called 0 times` — the renderer has no `DocumentWatermark` dependency yet.

- [ ] **Step 3: Implement**

`app-modules/admission/src/Documents/TicketDocumentRenderer.php` (full replacement):

```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Platform\Documents\DocumentWatermark;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand
 * (and its operator) are resolved once from the first admission rather
 * than per page. Both are read through plain queries, never
 * `$admission->brand`/`$brand->operator` (see Task 19's note on
 * `Model::shouldBeStrict()`).
 *
 * This task adds the footer band and the first two of the three watermark
 * layers (§4.3): a low-opacity brand mark and a diagonal forensic repeat,
 * both produced by one call into Platform's `DocumentWatermark::html()`.
 * The forensic lines are composed here, as plain strings — the service
 * itself "knows nothing about admissions". Task 22 adds the third layer,
 * TESTSYSTEM.
 *
 * The operator has no logo field (only a name and a legal name), so its
 * presence on the page is textual only — the footer band and the
 * forensic repeat, never an image.
 */
final class TicketDocumentRenderer
{
    private const int QR_SIZE_PX = 320;

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
        private readonly IssueAdmissionToken $issueAdmissionToken,
        private readonly DocumentWatermark $watermark,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->with('operator')->find($brandId);
        $operator = $brand?->operator;

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);
        $people = $this->peopleFor($admissions);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $operator,
            $eventSummaries->get($admission->event_id),
            $admission->holder_user_id === null ? null : $people->get($admission->holder_user_id),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, EventSummary> keyed by event_id
     */
    private function eventSummariesFor(Collection $admissions, ?string $brandId): Collection
    {
        if ($brandId === null) {
            return collect();
        }

        return EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->get()
            ->keyBy('event_id');
    }

    /**
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, Person>
     */
    private function peopleFor(Collection $admissions): Collection
    {
        $holderIds = $admissions->pluck('holder_user_id')->filter()->unique()->values();

        if ($holderIds->isEmpty()) {
            return collect();
        }

        return Person::query()->whereIn('id', $holderIds)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?Operator $operator, ?EventSummary $event, ?Person $holder): array
    {
        $holderName = $holder?->display_name ?? 'Unassigned';
        $role = str_replace('_', ' ', $admission->role);
        $brandLogo = $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id);

        // §4.3: holder name, event name, venue, operator legal name, role
        // and the admission id, in that order. Nulls (a missing summary,
        // no venue, no operator) are dropped rather than printed as gaps.
        $forensicLines = array_values(array_filter([
            $holderName,
            $event?->name,
            $event?->venue_label,
            $operator?->legal_name,
            $role,
            $admission->id,
        ], fn (?string $line): bool => $line !== null && $line !== ''));

        return [
            'brandLogo' => $brandLogo,
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
            'holderName' => $holderName,
            'role' => $role,
            'validRange' => $this->validRangeFor($admission),
            'doorCode' => $this->doorCode($admission->id),
            'qr' => $this->qrDataUri($this->issueAdmissionToken->current($admission)),
            'operatorLegalName' => $operator?->legal_name,
            'brandName' => $brand?->name,
            'admissionId' => $admission->id,
            // The brand mark and the forensic repeat, deterministically
            // seeded from the admission id so two renders of the same
            // ticket look the same.
            'watermarkHtml' => $this->watermark->html($forensicLines, $brandLogo, $admission->id),
        ];
    }

    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at, $event->timezone),
            $this->dates->dateTime($event->ends_at, $event->timezone),
            $event->timezone,
        );
    }

    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return 'All event days';
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }

    private function doorCode(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }

    private function qrDataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE_PX, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
```

`app-modules/admission/resources/views/tickets/document.blade.php` (full replacement — the event and holder bands are unchanged in content; they are now wrapped, with the footer band, in a `.content` layer that sits above a new `.watermark-layer`):

```blade
{{--
    Ticket document (#72 §4), rendered by
    `Konventix\Admission\Documents\TicketDocumentRenderer` through dompdf.

    Same constraints as the badge and the invoice: block layout, inline
    CSS, no flexbox or grid, and NO external assets — every image arrives
    as a `data:` URI because dompdf ships with remote fetching disabled.
    Semantic theming (ADR-0008) does not apply here, matching the badge
    and the invoice.

    One `.ticket-page` per admission, `page-break-before` on every page
    after the first so dompdf never adds a trailing blank page.

    Watermark stacking (§4.3): `.watermark-layer` (the brand mark and the
    forensic repeat, from `DocumentWatermark::html()`) is painted first,
    full-page. `.content` — the three bands — is a second, later,
    full-page absolutely-positioned sibling, so dompdf paints it on top of
    the watermark automatically: two elements at the same stacking level
    (position:absolute, z-index:auto) paint in document order. The bands
    themselves have no background, so the watermark shows through
    everywhere except `.qr-box`, which is opaque — "a clear rectangle kept
    around the QR... by stacking the QR in an opaque box above the
    watermark layer, not by computing coordinates".
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
        .ticket-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
        }
        .watermark-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .event-band {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 90mm;
            padding: 14mm 16mm;
            box-sizing: border-box;
            border-bottom: 0.75pt solid #d1d5db;
        }
        .event-band .logo { max-height: 24mm; max-width: 60mm; margin-bottom: 8mm; }
        .event-band .event-name { font-size: 20pt; font-weight: bold; margin: 0 0 4mm; }
        .event-band .event-when { font-size: 11pt; color: #374151; margin: 0 0 2mm; }
        .event-band .venue { font-size: 11pt; color: #374151; }

        .holder-band {
            position: absolute;
            top: 90mm;
            left: 0;
            width: 100%;
            height: 165mm;
            padding: 12mm 16mm;
            box-sizing: border-box;
            text-align: center;
        }
        .qr-box {
            display: inline-block;
            background-color: #ffffff;
            padding: 10px;
            margin: 4mm 0 6mm;
        }
        .qr-box img { width: 320px; height: 320px; display: block; }
        .holder-name { font-size: 18pt; font-weight: bold; margin: 0 0 2mm; }
        .holder-name.unassigned { font-style: italic; font-weight: normal; color: #6b7280; }
        .role-badge {
            display: inline-block;
            margin: 2mm 0 6mm;
            padding: 2mm 6mm;
            border-radius: 10pt;
            font-size: 9pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffffff;
            background-color: #1f2937;
        }
        .valid-range { font-size: 10pt; color: #374151; margin-bottom: 2mm; }
        .door-code { font-size: 8pt; color: #9ca3af; letter-spacing: 0.06em; }

        .footer-band {
            position: absolute;
            top: 255mm;
            left: 0;
            width: 100%;
            height: 42mm;
            padding: 10mm 16mm;
            box-sizing: border-box;
            border-top: 0.75pt solid #d1d5db;
            font-size: 8pt;
            color: #6b7280;
        }
        .footer-band .line { margin-bottom: 1.5mm; }
        .footer-band .ticket-id { font-family: 'DejaVu Sans Mono', monospace; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="watermark-layer">{!! $page['watermarkHtml'] !!}</div>

        <div class="content">
            <div class="event-band">
                @if ($page['brandLogo'] !== null)
                    <img class="logo" src="{{ $page['brandLogo'] }}" alt="">
                @endif

                <div class="event-name">{{ $page['eventName'] ?? __('Event details unavailable') }}</div>

                @if ($page['eventWhen'] !== null)
                    <div class="event-when">{{ $page['eventWhen'] }}</div>
                @endif

                @if ($page['venueLabel'] !== null)
                    <div class="venue">{{ $page['venueLabel'] }}</div>
                @endif
            </div>

            <div class="holder-band">
                <div class="qr-box"><img src="{{ $page['qr'] }}" alt=""></div>

                @if ($page['holderName'] === 'Unassigned')
                    <div class="holder-name unassigned">{{ __('Unassigned') }}</div>
                @else
                    <div class="holder-name">{{ $page['holderName'] }}</div>
                @endif

                <div class="role-badge">{{ $page['role'] }}</div>
                <div class="valid-range">{{ $page['validRange'] }}</div>
                <div class="door-code">{{ __('Door code') }}: {{ $page['doorCode'] }}</div>
            </div>

            <div class="footer-band">
                <div class="line">{{ __('Issued by') }} {{ $page['operatorLegalName'] }} &middot; {{ $page['brandName'] }}</div>
                <div class="line ticket-id">{{ __('Ticket ID') }}: {{ $page['admissionId'] }}</div>
                {{-- Out of scope (§12): the full purchase conditions are
                     deliberately not printed — the buyer already consented
                     at checkout, and reprinting them here would add pages. --}}
                <div class="line">{{ __('Full terms: see the confirmation you received when you registered.') }}</div>
            </div>
        </div>
    </div>
@endforeach
</body>
</html>
```

- [ ] **Step 4: Run it to verify it passes**

Run the same command as Step 2. Expected: PASS, all 9 examples green.

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

`app-modules/admission/TODO.md` — insert as the first bullet under `## Done`:

```markdown
- [x] Ticket PDF, part 3 — the footer band and the first two watermark
      layers, through Platform's `DocumentWatermark` service: a
      low-opacity brand mark and a diagonal forensic repeat seeded from
      the admission id, with the QR kept scannable by an opaque box
      painted over it (a later, second full-page absolute sibling) rather
      than by computing where the repeat may not go (#72 §4.3). The
      operator's presence on the page is textual only — it has no logo.
```

```bash
git add app-modules/admission/src/Documents/TicketDocumentRenderer.php \
        app-modules/admission/resources/views/tickets/document.blade.php \
        app-modules/admission/tests/TicketDocumentTest.php \
        app-modules/admission/TODO.md
git commit -m "Add the ticket PDF's footer band and watermark layers (#72)"
```

---

### Task 22: The TESTSYSTEM layer

**Files:**
- Modify: `app-modules/admission/src/Documents/TicketDocumentRenderer.php`
- Modify: `app-modules/admission/resources/views/tickets/document.blade.php`
- Modify: `app-modules/admission/tests/TicketDocumentTest.php`
- Modify: `app-modules/admission/TODO.md`

**Interfaces:**
- Consumes: `Konventix\Platform\Documents\DocumentWatermark::testSystemBanner(): string`
- Produces: `TicketDocumentRenderer` is complete for this section — event band, holder band, footer band, all three watermark layers; `render(Collection $admissions): string` is its only public entry point, unchanged since Task 19

- [ ] **Step 1: Write the failing test**

Append to `app-modules/admission/tests/TicketDocumentTest.php`:

```php
it('asks the watermark service for the TESTSYSTEM band on every page', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    $this->mock(DocumentWatermark::class, function (MockInterface $mock): void {
        $mock->shouldReceive('html')->andReturn('');
        $mock->shouldReceive('testSystemBanner')
            ->once()
            ->andReturn('<div class="testsystem">TESTSYSTEM</div>');
    });

    $bytes = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});

it('shows the TESTSYSTEM band off production and hides it on production, through DeploymentEnvironment', function (): void {
    // The real (unmocked) DocumentWatermark and DeploymentEnvironment —
    // the binding case is end-to-end, driven only through config, never
    // through env() (ADR-0053).
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    // The suite runs as `testing`, which is not production
    // (DeploymentEnvironmentTest.php).
    $nonProduction = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    config(['app.env' => 'production']);
    $production = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($nonProduction, '%PDF'))->toBeTrue()
        ->and(str_starts_with($production, '%PDF'))->toBeTrue()
        ->and(strlen($nonProduction))->toBeGreaterThan(strlen($production));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDocumentTest.php`
Expected: FAIL — `Mockery\Exception\InvalidCountException: Method testSystemBanner(<Any Arguments>) from Mockery_...DocumentWatermark should be called exactly 1 times but called 0 times`, and on the second new example `Failed asserting that <n> is greater than <n>` — the non-production and production renders are byte-identical because the renderer never calls `testSystemBanner()` yet.

- [ ] **Step 3: Implement**

`app-modules/admission/src/Documents/TicketDocumentRenderer.php` — one line added to `pageDataFor()`'s returned array, directly after `'watermarkHtml'`:

```php
            'watermarkHtml' => $this->watermark->html($forensicLines, $brandLogo, $admission->id),
            // §4.3 layer 3: a single diagonal band, empty on production.
            // `DocumentWatermark::testSystemBanner()` asks
            // `DeploymentEnvironment` itself — this renderer never reads
            // `app.env` or calls `env()` (ADR-0053).
            'testSystemHtml' => $this->watermark->testSystemBanner(),
```

The rest of the class (constructor, `render()`, `eventSummariesFor()`, `peopleFor()`, `eventWhenFor()`, `validRangeFor()`, `doorCode()`, `qrDataUri()`) is unchanged from Task 21.

`app-modules/admission/resources/views/tickets/document.blade.php` — the `<style>` block gains one rule and the `.ticket-page` gains a third, final sibling, painted last so it sits above everything, including the content bands (unlike the forensic repeat, TESTSYSTEM is meant to be unmissable on a screenshot, not merely present):

```blade
        .testsystem-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
```

```blade
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="watermark-layer">{!! $page['watermarkHtml'] !!}</div>

        <div class="content">
            {{-- event-band, holder-band, footer-band: unchanged from Task 21 --}}
        </div>

        <div class="testsystem-layer">{!! $page['testSystemHtml'] !!}</div>
    </div>
```

(Full file: identical to Task 21's version, with the `.testsystem-layer` style rule added inside `<style>` and the `<div class="testsystem-layer">{!! $page['testSystemHtml'] !!}</div>` line added as the last child of `.ticket-page`, after the closing `</div>` of `.content`.)

- [ ] **Step 4: Run it to verify it passes**

Run the same command as Step 2. Expected: PASS, all 11 examples green.

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`

`app-modules/admission/TODO.md` — insert as the first bullet under `## Done`:

```markdown
- [x] Ticket PDF, part 4 — the TESTSYSTEM band, drawn only when
      `DeploymentEnvironment::isProduction()` is false, painted last so it
      sits above the content bands too (#72 §4.3, ADR-0053).
      `Documents\TicketDocumentRenderer` is done: event band, holder band,
      footer band, all three watermark layers. The download routes are the
      next section's work.
```

```bash
git add app-modules/admission/src/Documents/TicketDocumentRenderer.php \
        app-modules/admission/resources/views/tickets/document.blade.php \
        app-modules/admission/tests/TicketDocumentTest.php \
        app-modules/admission/TODO.md
git commit -m "Add the ticket PDF's TESTSYSTEM watermark band (#72)"
```
# Tasks 23–27: how a participant reaches their ticket

Three surfaces, all in the Admission module (design §4.1, §7, §11; §13
amendments of 2026-09-11): the buyer's combined file, the holder's own list
and file, and the link on the participant's registration page. Every route
authorises entirely on `admissions.buyer_user_id` / `admissions.holder_user_id`
— never on a Registration table (ADR-0004) — and every download refuses a
revoked admission and stores nothing (§4.4).

Sequenced so nothing forward-references code that does not exist yet:
`TicketDownloadController::buyer()` (23) then `::holder()` (24) — both
before the holder's list page (25) links to `::holder()`, and before the
`registration.mine.show` slot (27) links to `::buyer()`. The "My tickets"
nav entry (26) is its own small task because it is testable on its own
(`accountNav`) and depends only on the list page's route name.

---

### Task 23: The buyer's combined ticket file

**Files:**
- Create: `app-modules/admission/src/Http/Controllers/TicketDownloadController.php`
- Modify: `app-modules/admission/routes/web/admission.php` (insert one import after line 20; append a new route block after line 50, becoming lines 52–63)
- Test: `app-modules/admission/tests/TicketDownloadControllerTest.php` (create)

**Interfaces:**
- Consumes: `Konventix\Admission\Documents\TicketDocumentRenderer::render(\Illuminate\Support\Collection $admissions): string`; `admissions.buyer_user_id` (nullable ULID); `Konventix\Admission\Models\EventSummary` (`event_id`, `slug`).
- Produces: `final class Konventix\Admission\Http\Controllers\TicketDownloadController` with `buyer(Request $request, string $registration, TicketDocumentRenderer $renderer): SymfonyResponse` — Task 24 appends `holder()` to this same class. Route `admission.tickets.buyer` (`GET /my/registrations/{registration}/ticket`) — Task 27's slot links to it.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;

/**
 * The buyer's combined ticket file (design §4.1, §7): every admission the
 * registration granted, one page each, ordered by creation, a revoked one
 * excluded. Authorised entirely on `admissions.buyer_user_id` — never a
 * Registration table (ADR-0004). Following `BadgeDocumentTest`'s own rule
 * (design §11), a rendered PDF is asserted on its magic bytes and its page
 * count, never byte-compared.
 */
it('downloads one page per non-revoked admission, and nothing for a revoked one', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $buyer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    Admission::factory()->count(2)->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
    ]);
    Admission::factory()->revoked()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
    ]);

    $response = $this->actingAs($buyer)
        ->get('/my/registrations/'.$registrationId.'/ticket')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');

    $bytes = (string) $response->getContent();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        // A page object is `/Type /Page`, never `/Type /Pages` — the parent
        // tree node dompdf also writes once per document, and the negative
        // lookahead is what keeps that one node out of the count.
        ->and(preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes))->toBe(2);
});

it('hands the renderer the buyer\'s own admissions in creation order', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    $second = Admission::factory()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
        'created_at' => now()->addMinute(),
    ]);
    $first = Admission::factory()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
        'created_at' => now(),
    ]);

    $this->mock(TicketDocumentRenderer::class, function ($mock) use ($first, $second): void {
        $mock->shouldReceive('render')
            ->once()
            ->withArgs(fn (Collection $admissions): bool => $admissions->pluck('id')->all() === [$first->id, $second->id])
            ->andReturn("%PDF-1.4\n%fake\n");
    });

    $this->actingAs($buyer)
        ->get('/my/registrations/'.$registrationId.'/ticket')
        ->assertOk();
});

it('refuses a stranger and an unrelated registration id with 403', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $registrationId = (string) Str::ulid();

    Admission::factory()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
    ]);

    $this->actingAs($stranger)
        ->get('/my/registrations/'.$registrationId.'/ticket')
        ->assertForbidden();

    $this->actingAs($buyer)
        ->get('/my/registrations/'.Str::ulid().'/ticket')
        ->assertForbidden();
});

it('answers 404 once every admission the buyer owns on this registration has been revoked', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    Admission::factory()->revoked()->create([
        'registration_id' => $registrationId,
        'buyer_user_id' => $buyer->getKey(),
    ]);

    $this->actingAs($buyer)
        ->get('/my/registrations/'.$registrationId.'/ticket')
        ->assertNotFound();
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDownloadControllerTest.php`
Expected: FAIL — `/my/registrations/{registration}/ticket` has no route yet, so every request 404s before reaching any assertion about its body: `downloads one page per non-revoked admission, and nothing for a revoked one` fails with `Expected response status code [200] but received 404.`
- [ ] **Step 3: Implement**
```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The buyer's whole file (design §4.1, §7). Authorised entirely on
 * Admission's own columns — never by reading a Registration row
 * (ADR-0004), the same reasoning that keeps Resources' seat picker off
 * Registration's tables. Stores nothing:
 * `TicketDocumentRenderer::render()` returns bytes, mirroring the badge
 * (§4.4).
 */
final class TicketDownloadController
{
    /**
     * One page per admission the registration granted, in the order they
     * were issued, a revoked one never among them. `{registration}` stays
     * a plain ULID rather than a route-model binding — Admission may not
     * import Registration's model (ADR-0004) — so ownership is decided
     * entirely by whether at least one admission this registration granted
     * names the signed-in account as `buyer_user_id`; a stranger and a
     * registration id this module has never heard of answer the same 403,
     * the information shape `MyRegistrationsController::show`'s inline
     * ownership check already keeps.
     */
    public function buyer(Request $request, string $registration, TicketDocumentRenderer $renderer): SymfonyResponse
    {
        abort_unless(
            Admission::query()
                ->where('registration_id', $registration)
                ->where('buyer_user_id', (string) $request->user()->getAuthIdentifier())
                ->exists(),
            403,
        );

        $admissions = Admission::query()
            ->where('registration_id', $registration)
            ->where('buyer_user_id', (string) $request->user()->getAuthIdentifier())
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderBy('created_at')
            ->get();

        // Every admission this buyer owns on this registration has since
        // been revoked: nothing left to serve, and a "download" opening to
        // a blank document would be worse than a 404.
        abort_if($admissions->isEmpty(), 404);

        $bytes = $renderer->render($admissions);

        $slug = EventSummary::query()->where('event_id', $admissions->first()->event_id)->value('slug')
            ?? $admissions->first()->event_id;

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="tickets-%s.pdf"', $slug),
        ]);
    }
}
```
```php
// app-modules/admission/routes/web/admission.php — insert after line 20
// (`use Konventix\Admission\Http\Controllers\EntranceClientAssetController;`):
use Konventix\Admission\Http\Controllers\TicketDownloadController;

// append at the end of the file (becomes lines 52-63):

/*
 * The buyer's combined ticket file (design §4.1, §7): every admission the
 * registration granted, one page each, ordered by creation. Authorised
 * entirely on `admissions.buyer_user_id` — never a Registration table
 * (ADR-0004) — which is why `{registration}` stays a plain ULID rather than
 * a route-model binding, exactly as Resources' seat picker does it.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/registrations/{registration}/ticket', [TicketDownloadController::class, 'buyer'])
        ->name('admission.tickets.buyer');
});
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDownloadControllerTest.php`
Expected: all four tests PASS.
- [ ] **Step 5: Verify and commit**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Http/Controllers/TicketDownloadController.php \
        app-modules/admission/routes/web/admission.php \
        app-modules/admission/tests/TicketDownloadControllerTest.php
git commit -m "Let a registration's buyer download every admission it granted as one combined PDF (#72)."
```

---

### Task 24: The holder's own ticket file

**Files:**
- Modify: `app-modules/admission/src/Http/Controllers/TicketDownloadController.php` (append a `holder()` method before the closing `}` at line 69)
- Modify: `app-modules/admission/routes/web/admission.php` (append a new route block after line 63, becoming lines 65–74)
- Test: `app-modules/admission/tests/TicketDownloadControllerTest.php` (modify — append holder tests)

**Interfaces:**
- Consumes: `TicketDocumentRenderer::render()` (Task 23); `admissions.holder_user_id`; `EventSummary` (`event_id`, `slug`).
- Produces: `TicketDownloadController::holder(Request $request, Admission $admission, TicketDocumentRenderer $renderer): SymfonyResponse`. Route `admission.tickets.holder` (`GET /my/admissions/{admission}/ticket`) — the Wayfinder action `TicketDownloadController.holder` that Task 25's list page and nothing else needs.

- [ ] **Step 1: Write the failing test**
```php
// appended to app-modules/admission/tests/TicketDownloadControllerTest.php

it('downloads a single-page PDF for the holder', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $holder = User::factory()->create();
    $admission = Admission::factory()->create(['holder_user_id' => $holder->getKey()]);

    $response = $this->actingAs($holder)
        ->get('/my/admissions/'.$admission->getKey().'/ticket')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');

    $bytes = (string) $response->getContent();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes))->toBe(1);
});

it('downloads a staff admission\'s ticket even though it carries no registration', function (): void {
    // D-STF-01: a rostered crew member's admission has no `registration_id`
    // at all, so the route above has nothing to key on — this route is the
    // only one that can ever serve its ticket.
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $crew = User::factory()->create();
    $admission = Admission::factory()->create([
        'registration_id' => null,
        'holder_user_id' => $crew->getKey(),
        'role' => 'crew',
    ]);

    $this->actingAs($crew)
        ->get('/my/admissions/'.$admission->getKey().'/ticket')
        ->assertOk();
});

it('refuses a stranger holder with 403', function (): void {
    $this->withBrand();
    $holder = User::factory()->create();
    $stranger = User::factory()->create();
    $admission = Admission::factory()->create(['holder_user_id' => $holder->getKey()]);

    $this->actingAs($stranger)
        ->get('/my/admissions/'.$admission->getKey().'/ticket')
        ->assertForbidden();
});

it('refuses a revoked admission with 404', function (): void {
    $this->withBrand();
    $holder = User::factory()->create();
    $admission = Admission::factory()->revoked()->create(['holder_user_id' => $holder->getKey()]);

    $this->actingAs($holder)
        ->get('/my/admissions/'.$admission->getKey().'/ticket')
        ->assertNotFound();
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDownloadControllerTest.php`
Expected: the four new tests FAIL — `/my/admissions/{admission}/ticket` has no route yet: `refuses a stranger holder with 403` fails with `Expected response status code [403] but received 404.` The four Task 23 tests still PASS.
- [ ] **Step 3: Implement**
```php
// appended to app-modules/admission/src/Http/Controllers/TicketDownloadController.php,
// inside the class, after buyer() — no new imports, Admission/AdmissionState/
// EventSummary/Response/SymfonyResponse are already imported for buyer():

    /**
     * The counterpart to `buyer()` above, and the only route that can ever
     * serve a staff admission's ticket: `registration_id` is null for one
     * (D-STF-01), so `buyer()` has nothing to key on for it.
     */
    public function holder(Request $request, Admission $admission, TicketDocumentRenderer $renderer): SymfonyResponse
    {
        abort_unless($admission->holder_user_id === (string) $request->user()->getAuthIdentifier(), 403);
        abort_if($admission->state === AdmissionState::Revoked, 404, 'This ticket has been revoked.');

        $bytes = $renderer->render(collect([$admission]));

        $slug = EventSummary::query()->where('event_id', $admission->event_id)->value('slug')
            ?? $admission->event_id;

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="ticket-%s.pdf"', $slug),
        ]);
    }
```
```php
// app-modules/admission/routes/web/admission.php — append after line 63
// (becomes lines 65-74):

/*
 * The holder's own single-page file (design §4.1, §7): the counterpart to
 * the route above, and — for a staff admission, which carries no
 * `registration_id` at all (D-STF-01) — the only route that can ever serve
 * its ticket.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/admissions/{admission}/ticket', [TicketDownloadController::class, 'holder'])
        ->name('admission.tickets.holder');
});
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/TicketDownloadControllerTest.php`
Expected: all eight tests PASS.
- [ ] **Step 5: Verify and commit**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Http/Controllers/TicketDownloadController.php \
        app-modules/admission/routes/web/admission.php \
        app-modules/admission/tests/TicketDownloadControllerTest.php
git commit -m "Let an admission's holder download their own single ticket, including a staff admission with no registration (#72)."
```

---

### Task 25: The holder's ticket list page

**Files:**
- Create: `app-modules/admission/src/Data/MyTicketData.php`
- Create: `app-modules/admission/src/Http/Controllers/MyTicketsController.php`
- Create: `app-modules/admission/resources/js/pages/admission/tickets/Index.vue`
- Modify: `app-modules/admission/routes/web/admission.php` (insert one import after line 20; append a new route block after line 74, becoming lines 77–87)
- Test: `app-modules/admission/tests/MyTicketsControllerTest.php` (create)

**Interfaces:**
- Consumes: `admissions.holder_user_id`; `EventSummary` (`event_id`, `name`); Wayfinder action `TicketDownloadController.holder` (Task 24).
- Produces: `Konventix\Admission\Data\MyTicketData::ofModel(Admission $admission, ?string $eventName): self`; `MyTicketsController::index(Request $request): Response`; route `admission.tickets.mine` (`GET /my/tickets`) — the URL Task 26's nav entry points at.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;

/**
 * The holder's own tickets (design §4.1, §7): filtered entirely on
 * `admissions.holder_user_id` — the filter *is* the authorization, the
 * same shape `MyRegistrationsController::index` keeps for its own list. An
 * admission nobody has claimed through the existing claim links appears
 * here for nobody (ADR-0032, §13's 2026-09-11 amendment) — its ticket
 * reaches its buyer through `TicketDownloadController::buyer()` instead.
 */
it('lists only the signed-in account\'s own claimed, non-revoked admissions', function (): void {
    $this->withBrand();
    $holder = User::factory()->create();
    $stranger = User::factory()->create();

    $mine = Admission::factory()->create([
        'holder_user_id' => $holder->getKey(),
        'role' => 'visitor',
    ]);
    Admission::factory()->create(['holder_user_id' => $stranger->getKey()]);
    Admission::factory()->revoked()->create(['holder_user_id' => $holder->getKey()]);

    $this->actingAs($holder)
        ->get('/my/tickets')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admission/tickets/Index')
            ->has('tickets', 1)
            ->where('tickets.0.id', $mine->getKey())
            ->where('tickets.0.role', 'visitor'));
});

it('shows an unclaimed admission for nobody, including its own buyer', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();

    Admission::factory()->create([
        'buyer_user_id' => $buyer->getKey(),
        'holder_user_id' => null,
    ]);

    $this->actingAs($buyer)
        ->get('/my/tickets')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 0));
});

it('lists a staff admission that carries no registration at all', function (): void {
    $this->withBrand();
    $crew = User::factory()->create();

    Admission::factory()->create([
        'registration_id' => null,
        'holder_user_id' => $crew->getKey(),
        'role' => 'crew',
    ]);

    $this->actingAs($crew)
        ->get('/my/tickets')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 1));
});

it('sends a signed-out visitor to log in', function (): void {
    $this->withBrand();

    $this->get('/my/tickets')->assertRedirect('/login');
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/MyTicketsControllerTest.php`
Expected: FAIL — `/my/tickets` has no route yet: `lists only the signed-in account's own claimed, non-revoked admissions` fails with `Expected response status code [200] but received 404.`
- [ ] **Step 3: Implement**
```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Konventix\Admission\Models\Admission;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the holder's own admissions, as `/my/tickets` shows it (design
 * §4.1, §7). `eventName` comes from this module's own `EventSummary` read
 * model, never from Event's tables (ADR-0004), and falls back to the ULID
 * the same way `MyRegistrationData::ofModel()` does — an admission whose
 * event summary has not arrived yet is still the holder's, and still has
 * to render.
 */
#[TypeScript]
final class MyTicketData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly string $role,
        public readonly string $state,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     */
    public static function ofModel(Admission $admission, ?string $eventName): self
    {
        return new self(
            id: $admission->getKey(),
            eventId: $admission->event_id,
            eventName: $eventName ?? $admission->event_id,
            role: $admission->role,
            state: $admission->state->value,
        );
    }
}
```
```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Data\MyTicketData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;

/**
 * The holder's own tickets (design §4.1, §7): every admission whose
 * `holder_user_id` is the signed-in account, whichever registration — or
 * none, for a staff admission (D-STF-01) — produced it.
 */
final class MyTicketsController
{
    public function index(Request $request): Response
    {
        $admissions = Admission::query()
            ->where('holder_user_id', (string) $request->user()->getAuthIdentifier())
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderByDesc('created_at')
            ->get();

        $eventNames = EventSummary::query()
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->pluck('name', 'event_id');

        return Inertia::render('admission/tickets/Index', [
            'tickets' => $admissions
                ->map(fn (Admission $admission): array => MyTicketData::ofModel(
                    $admission,
                    $eventNames->get($admission->event_id),
                )->toArray())
                ->all(),
        ]);
    }
}
```
```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileDown } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';

/**
 * "What can I get into" — the holder's own tickets, reached from the
 * account menu (design §4.1, §7). An admission nobody has claimed through
 * the existing claim links never appears here for anybody — its ticket
 * reaches its buyer through the buyer's own combined file instead.
 *
 * Each row downloads on its own: there is no detail page between the list
 * and the PDF, so the whole row is the download link — a plain anchor, not
 * an Inertia `<Link>`, the same reasoning `RegistrationSettlement.vue`'s
 * invoice download follows (it streams a PDF; an Inertia visit expects a
 * page component back).
 */
type Ticket = Konventix.Admission.Data.MyTicketData;

const props = defineProps<{
    tickets: Ticket[];
}>();

const stateTone: Record<string, string> = {
    issued: 'bg-success/15 text-success',
    assigned: 'bg-success/15 text-success',
    expired: 'bg-subtle text-text-muted',
};
</script>

<template>
    <Head title="My tickets" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            as="h1"
            title="My tickets"
            description="Every admission claimed under your account"
        />

        <p v-if="props.tickets.length === 0" class="text-sm text-text-muted">
            No ticket has been claimed to your account yet.
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="ticket in props.tickets" :key="ticket.id">
                <a
                    :href="TicketDownloadController.holder.url(ticket.id)"
                    class="flex items-center gap-4 rounded-xl border border-border bg-surface-raised p-4 transition hover:border-accent"
                >
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="font-medium text-text">
                            {{ ticket.eventName }}
                        </span>
                        <span class="flex flex-wrap items-center gap-2 pt-1">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                :class="
                                    stateTone[ticket.state] ??
                                    'bg-subtle text-text'
                                "
                            >
                                {{ ticket.role }}
                            </span>
                        </span>
                    </span>

                    <FileDown
                        class="size-4 shrink-0 text-text-muted"
                        aria-hidden="true"
                    />
                </a>
            </li>
        </ul>
    </div>
</template>
```
```php
// app-modules/admission/routes/web/admission.php — insert after line 20
// (`use Konventix\Admission\Http\Controllers\EntranceClientAssetController;`),
// before the `TicketDownloadController` import (alphabetical: M < T):
use Konventix\Admission\Http\Controllers\MyTicketsController;

// append at the end of the file (becomes lines 77-87):

/*
 * The holder's own list (design §4.1, §7): every admission whose
 * `holder_user_id` is the signed-in account, whichever registration — if
 * any — produced it. An admission nobody has claimed appears here for
 * nobody; its ticket reaches its buyer through the buyer's own route
 * instead.
 */
Route::middleware(['auth'])->group(function (): void {
    Route::get('/my/tickets', [MyTicketsController::class, 'index'])
        ->name('admission.tickets.mine');
});
```
Because this adds a new Inertia **page** rather than only a slot component, the Vite manifest must know about it before the test can render it (`.ai/rules/tests.md`, "A new module page needs npm run build"): run `vendor/bin/sail npm run build` (or have `npm run dev`/`composer run dev` already watching) before Step 4.
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/MyTicketsControllerTest.php`
Expected: all four tests PASS.
- [ ] **Step 5: Verify and commit**
`MyTicketData` is a new Data class, so this task ends with the TypeScript pipeline before verifying:
```bash
printf '{\n    "generated.d.ts": "0"\n}\n' > resources/js/types/typescript-transformer-manifest.json
flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer types:generate
npx prettier --write resources/js/types
```
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Data/MyTicketData.php \
        app-modules/admission/src/Http/Controllers/MyTicketsController.php \
        app-modules/admission/resources/js/pages/admission/tickets/Index.vue \
        app-modules/admission/routes/web/admission.php \
        app-modules/admission/tests/MyTicketsControllerTest.php \
        resources/js/types/generated.d.ts \
        resources/js/types/typescript-transformer-manifest.json
git commit -m "Give a ticket holder their own list of claimed admissions to download from (#72)."
```

---

### Task 26: The "My tickets" navigation entry

**Files:**
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert two imports after line 19; insert a new `registerNavigation()` method after line 117, becoming lines 121–141)
- Test: `app-modules/admission/tests/MyTicketsControllerTest.php` (modify — append the nav test)

**Interfaces:**
- Consumes: `Konventix\Platform\Navigation\NavigationRegistry::add(NavigationItem ...$items)`; route `admission.tickets.mine` (Task 25); the shared Inertia prop `accountNav` built by `app/Http/Middleware/HandleInertiaRequests.php`.
- Produces: a `NavigationItem` labelled "My tickets" (`module: 'admission'`, `area: NavigationItem::AREA_ACCOUNT`, `url: '/my/tickets'`) visible in `props.accountNav` for any signed-in account.

- [ ] **Step 1: Write the failing test**
```php
// appended to app-modules/admission/tests/MyTicketsControllerTest.php

it('adds "My tickets" to the account navigation', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['accountNav'])->pluck('label');

            expect($labels)->toContain('My tickets');
        });
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/MyTicketsControllerTest.php`
Expected: FAIL — `adds "My tickets" to the account navigation` fails with `Failed asserting that a collection contains "My tickets".` The other five tests in this file still PASS.
- [ ] **Step 3: Implement**
```php
// app-modules/admission/src/Providers/AdmissionServiceProvider.php — insert
// after line 19 (`use Konventix\Platform\Modules\ModuleServiceProvider;`):
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

// insert a new method after line 117 (the closing `}` of registerListeners()),
// before the blank line that precedes registerSlots()'s docblock — becomes
// lines 121-141:

    /**
     * The participant's own, in the account menu (design §7). No
     * `permission`, and none is missing: the menu renders only for a
     * signed-in user and `MyTicketsController::index()` authorizes on
     * ownership, which is not a capability anybody can be granted — the
     * same reasoning Registration's own `My registrations` entry already
     * documents.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'admission',
                label: 'My tickets',
                url: '/my/tickets',
                icon: 'qr-code',
                order: 11,
                area: NavigationItem::AREA_ACCOUNT,
            ),
        );
    }
```
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/MyTicketsControllerTest.php`
Expected: all six tests PASS.
- [ ] **Step 5: Verify and commit**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/tests/MyTicketsControllerTest.php
git commit -m "Add a 'My tickets' entry to the participant account menu (#72)."
```

---

### Task 27: The ticket link on the registration page

**Files:**
- Create: `app-modules/admission/src/Data/TicketLinkData.php`
- Modify: `app-modules/admission/src/Providers/AdmissionServiceProvider.php` (insert two imports before the `Enums\Permission` import; insert one import before the `Compliance\Gdpr\GdprHandlerRegistry` import; replace the body of `registerSlots()`)
- Create: `app-modules/admission/resources/js/components/TicketLinkSlot.vue`
- Test: `app-modules/admission/tests/RegistrationTicketSlotTest.php` (create)

**Interfaces:**
- Consumes: `Konventix\Platform\Slots\SlotRegistry::add(string $slot, string $module, string $component, array|Closure $props, int $order)`; the `registration.mine.show` slot's `$context['registrationId']`, populated by `MyRegistrationsController::show()`; `admissions.state`; Wayfinder action `TicketDownloadController.buyer` (Task 23).
- Produces: `Konventix\Admission\Data\TicketLinkData::of(string $registrationId, int $ticketCount): self`; the `admission/TicketLinkSlot` contribution to `registration.mine.show`. Nothing downstream in this plan depends on it further — this closes the section.

**A note on module gating.** The task brief for this section describes Admission as "a gateable module" whose deactivation should hide this route, nav entry and slot alike. The codebase disagrees on the record: `AdmissionServiceProvider` declares `protected bool $gateable = false;` (Core module, ADR-0003 / `.ai/rules/providers.md`), and `app-modules/platform/tests/ModuleActivationTest.php` already pins `admission` in its parametrized `'treats kernel and core modules as always active'` case — `ModuleGate::isActive('admission')` is unconditionally `true` and no Pennant feature can change that, because `isGateable('admission')` short-circuits before any feature is ever read. Making Admission gateable to satisfy that one scenario would contradict this settled architecture and the pipeline's own pinned test, and would also gate the entrance/claim/badge routes this section does not own. This task therefore registers the slot the unconditional way every other Core-module contribution to a UI slot already does (Registration's own two `registerSlots()` entries are the precedent), and carries no deactivation test.

- [ ] **Step 1: Write the failing test**
```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;
use Konventix\Registration\Models\Registration;

/**
 * Admission's contribution to the registrant's own page (design §7),
 * mirroring Commerce's `RegistrationSettlementSlotTest.php`: the route is
 * `registration.mine.show`, owned by Registration, which never reads this
 * module's tables (ADR-0004) — only the slot's props do.
 */
function ticketSlotProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'admission/TicketLinkSlot');

    return $slot === null ? null : $slot['props']['ticket'];
}

it('names how many tickets the buyer\'s file holds', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Admission::factory()->count(2)->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);
    // A revoked one does not count.
    Admission::factory()->revoked()->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page) use ($registration): void {
            $ticket = ticketSlotProps($page);

            expect($ticket['registrationId'])->toBe($registration->getKey())
                ->and($ticket['ticketCount'])->toBe(2);
        });
});

it('is absent when the registration granted no admissions', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(ticketSlotProps($page))->toBeNull());
});

it('is absent once every admission it granted has been revoked', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Admission::factory()->revoked()->create([
        'registration_id' => $registration->getKey(),
        'buyer_user_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(ticketSlotProps($page))->toBeNull());
});
```
- [ ] **Step 2: Run it to verify it fails**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/RegistrationTicketSlotTest.php`
Expected: FAIL — nothing has registered `admission/TicketLinkSlot` on `registration.mine.show` yet, so `ticketSlotProps()` returns `null` even where a ticket is expected: `names how many tickets the buyer's file holds` fails with `TypeError: Cannot access offset of type string on null` (indexing `$ticket['registrationId']` on a null `$ticket`).
- [ ] **Step 3: Implement**
```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Admission's contribution to the registrant's own page (design §7): how
 * many tickets the buyer's combined file holds, and the registration id
 * `TicketDownloadController::buyer()` needs to build the download link.
 * Absent entirely — `ticket: null` on the slot's props, `SeatSummaryData`'s
 * own idiom on this same slot — when the registration granted no
 * admissions, or every one it granted has since been revoked.
 */
#[TypeScript]
final class TicketLinkData extends Data
{
    public function __construct(
        public readonly string $registrationId,
        public readonly int $ticketCount,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     */
    public static function of(string $registrationId, int $ticketCount): self
    {
        return new self(
            registrationId: $registrationId,
            ticketCount: $ticketCount,
        );
    }
}
```
```php
// app-modules/admission/src/Providers/AdmissionServiceProvider.php — insert
// before the `use Konventix\Admission\Enums\Permission;` import:
use Konventix\Admission\Data\TicketLinkData;
use Konventix\Admission\Enums\AdmissionState;

// insert before the `use Konventix\Compliance\Gdpr\GdprHandlerRegistry;` import:
use Konventix\Admission\Models\Admission;

// replace the whole body of registerSlots():
    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'event.admin.dashboard',
            module: 'admission',
            component: 'admission/EventStatsCard',
            props: [],
            order: 20,
        );

        // design §7: how many tickets the buyer's file holds, and the link
        // to it — the same mechanism the seat picker's `SeatSummarySlot`
        // uses on this slot. Absent (`ticket: null`) when the registration
        // granted no admissions at all, or when every admission it granted
        // has since been revoked — never a link into an empty file.
        // `static`: SlotRegistry is a singleton that outlives the request
        // under Octane, and a closure capturing `$this` would pin this
        // provider in memory with it.
        $slots->add(
            slot: 'registration.mine.show',
            module: 'admission',
            component: 'admission/TicketLinkSlot',
            props: static function (array $context): array {
                $count = Admission::query()
                    ->where('registration_id', $context['registrationId'])
                    ->where('state', '!=', AdmissionState::Revoked)
                    ->count();

                if ($count === 0) {
                    return ['ticket' => null];
                }

                return ['ticket' => TicketLinkData::of($context['registrationId'], $count)->toArray()];
            },
            order: 30,
        );
    }
```
```vue
<script setup lang="ts">
import { Ticket as TicketIcon } from '@lucide/vue';
import TicketDownloadController from '@/actions/Konventix/Admission/Http/Controllers/TicketDownloadController';

/**
 * Admission's contribution to Registration's account page (design §7),
 * registered by `AdmissionServiceProvider::registerSlots()` on
 * `registration.mine.show`: how many tickets the buyer's file holds, and
 * the link to download it. `ticket` is null for a registration that
 * granted no admissions, or whose admissions have all been revoked since —
 * the same `null`-means-render-nothing idiom `SeatSummarySlot.vue` uses on
 * this same slot.
 *
 * The link is a plain anchor, not an Inertia `<Link>`: it streams a PDF,
 * and an Inertia visit would expect a page component back — the same
 * reasoning `RegistrationSettlement.vue`'s invoice download follows.
 */
type TicketLink = Konventix.Admission.Data.TicketLinkData;

const props = defineProps<{
    ticket: TicketLink | null;
}>();
</script>

<template>
    <section
        v-if="props.ticket"
        class="flex flex-col gap-3"
        aria-labelledby="registration-tickets"
    >
        <h2 id="registration-tickets" class="text-lg font-medium">
            {{ props.ticket.ticketCount === 1 ? 'Ticket' : 'Tickets' }}
        </h2>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-border bg-surface-raised p-4"
        >
            <span class="text-sm text-text-muted">
                {{
                    props.ticket.ticketCount === 1
                        ? 'Your ticket is ready to download.'
                        : `Your ${props.ticket.ticketCount} tickets are ready to download.`
                }}
            </span>
            <span class="flex-1" />
            <a
                :href="
                    TicketDownloadController.buyer.url(
                        props.ticket.registrationId,
                    )
                "
                class="inline-flex min-h-11 items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
            >
                <TicketIcon class="size-4" />
                Download
                {{ props.ticket.ticketCount === 1 ? 'ticket' : 'tickets' }}
                (PDF)
            </a>
        </div>
    </section>
</template>
```
Run `npx vue-tsc --noEmit` (`npm run types:check`) after the Data class regenerates its TypeScript, below, to confirm `TicketLinkSlot.vue` compiles against `Konventix.Admission.Data.TicketLinkData` — this slot component carries its own props as plain JSON, never through the Vite page manifest, so (unlike Task 25) no `npm run build` is required for the Pest test itself to pass.
- [ ] **Step 4: Run it to verify it passes**
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/RegistrationTicketSlotTest.php`
Expected: all three tests PASS.
- [ ] **Step 5: Verify and commit**
`TicketLinkData` is a new Data class, so this task ends with the TypeScript pipeline before verifying:
```bash
printf '{\n    "generated.d.ts": "0"\n}\n' > resources/js/types/typescript-transformer-manifest.json
flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer types:generate
npx prettier --write resources/js/types
npm run types:check
```
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- admission`
```bash
git add app-modules/admission/src/Data/TicketLinkData.php \
        app-modules/admission/src/Providers/AdmissionServiceProvider.php \
        app-modules/admission/resources/js/components/TicketLinkSlot.vue \
        app-modules/admission/tests/RegistrationTicketSlotTest.php \
        resources/js/types/generated.d.ts \
        resources/js/types/typescript-transformer-manifest.json
git commit -m "Link the buyer's ticket file from the registration page whenever it granted any admissions (#72)."
```
# Calendar section: Tasks 28-32 — the calendar file reaches people

Binding contract: `docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md`,
§6.1 (format, already built), §6.2 (public download), §6.3 (the mail
attachment), §7 (where the participant sees this), §11 (testing) and every
§13 amendment dated 2026-09-11.

This section consumes two things it does not build:
`Konventix\Platform\Calendar\CalendarFile::forEvent(string $uid, string $summary, ?string $description, ?string $location, ?string $url, DateTimeInterface $startsAt, DateTimeInterface $endsAt, ?DateTimeInterface $lastModifiedAt = null): string`
(Platform section, Tasks 3-4 — a hand-rolled RFC 5545 encoder, UTC instants,
no `VTIMEZONE`), and `registration_event_summaries`'s `starts_at`, `ends_at`,
`timezone` and `venue_label` columns, reached through
`Konventix\Registration\Models\EventSummary` (an earlier Registration-section
task extending `ProjectEventSummary` per §13's 2026-09-11 amendment). Neither
the encoder nor the projector is touched here. Admission and the read-model
projectors belong to other sections.

Five things, in dependency order: the public download (Event reads its own
models directly); the landing-page button that links to it (depends on the
route existing); `AttachmentData`'s inline form (Messaging, standalone);
`TemplatedMail` honouring it (depends on the inline form); the confirmation
mail attaching it (depends on all three: the inline form, `TemplatedMail`,
and Registration's own event summary).

**A correction found while reading, not the spec:** `EventServiceProvider`
sets `protected bool $gateable = false;` — Event is a **Kernel** module,
always active for every brand, not a Pennant-gated one. `ModuleServiceProvider::routeMiddleware()`
therefore wraps every route this section adds in `web` alone, with no
`ensure-module:event`, and `SlotRegistry::for()`'s activation filter
(`ModuleGate::isActive('event')`) always returns `true` for the same reason.
Nothing below applies or tests a module gate; there is none to apply.

**A second correction, from §6.1 vs. §13's venue amendment:** §6.1's body
says `LOCATION` is "the primary venue's name and address on one line."
§13's 2026-09-11 venue amendment narrows that to "name and city," but only
for the read-model consumers (`registration_event_summaries.venue_label`,
`admission_event_summaries`) whose only source is the `VenueAssigned`
contract event, which carries no street address. Task 28's public route is
not one of those consumers — `EventLandingController` reads Event's own
`Venue` rows directly, which do carry a full address (see `Landing.vue`'s
own venue block) — so Task 28 keeps §6.1's original "name and address," and
only Task 32's mail attachment is limited to `venue_label`'s "name and
city." Both are documented at their call sites below.

---

### Task 28: `GET /events/{event:slug}/calendar.ics` — the public download

**Files:**
- Modify: `app-modules/event/src/Http/Controllers/EventLandingController.php`
  (imports lines 1-13, constructor line 27; new `calendar()` and `hostFor()`
  methods inserted after `show()`, i.e. after line 122)
- Modify: `app-modules/event/routes/web/event.php` (append after line 35)
- Test: Create `app-modules/event/tests/EventCalendarRouteTest.php`

**Interfaces:**
- Consumes: `Konventix\Platform\Calendar\CalendarFile::forEvent(...)` (above);
  `Konventix\Platform\Brands\BrandUrl::baseFor(Brand $brand): ?string`;
  `Event::isVisibleOnLanding(): bool`, `Event::primaryVenue(): ?Venue`,
  `Event::startsAtInstant()`/`endsAtInstant(): Carbon` (all existing, `app-modules/event/src/Models/Event.php`).
- Produces: named route `event.calendar` (`GET /events/{event:slug}/calendar.ics`),
  which Task 29's Vue button links to via the Wayfinder helper generated at
  `resources/js/routes/event`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;

/**
 * Spec §6.2: `GET /events/{event:slug}/calendar.ics`, public, no
 * authentication, served as `text/calendar; charset=utf-8` with a filename
 * from the event slug. 404 matches the landing page's own rule exactly —
 * `Event::isVisibleOnLanding()` — because this file downloads from that
 * same page and must vanish with it.
 */
it('serves a parseable VCALENDAR as text/calendar with a filename from the slug', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $response = $this->get('/events/'.$event->slug.'/calendar.ics');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$event->slug.'.ics"');

    $body = $response->getContent();

    expect($body)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and(rtrim($body, "\r\n"))->toEndWith("END:VEVENT\r\nEND:VCALENDAR")
        ->and($body)->toContain('SUMMARY:'.$event->name);
});

it('404s a draft event, the same rule the landing page enforces', function (): void {
    $this->withBrand();

    $event = Event::factory()->create();

    $this->get('/events/'.$event->slug.'/calendar.ics')->assertNotFound();
});

it('yields the same UID across two requests for the same event', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $uidLine = fn (string $ics): ?string => collect(explode("\r\n", $ics))
        ->first(fn (string $line): bool => str_starts_with($line, 'UID:'));

    $first = $uidLine($this->get('/events/'.$event->slug.'/calendar.ics')->getContent());
    $second = $uidLine($this->get('/events/'.$event->slug.'/calendar.ics')->getContent());

    // withBrand() binds the shared test brand to 'localhost' with is_primary
    // true (tests/TestCase.php), so BrandUrl::baseFor() resolves that host.
    expect($first)->toBe($second)
        ->and($first)->toBe('UID:'.$event->id.'@localhost');
});

it('still produces a valid file for an event with no venue', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    expect($event->venues)->toBeEmpty();

    $response = $this->get('/events/'.$event->slug.'/calendar.ics');

    $response->assertOk();
    expect($response->getContent())->not->toContain('LOCATION:');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/EventCalendarRouteTest.php`
Expected: FAIL — no route matches `/events/{slug}/calendar.ics` yet, so every request 404s before reaching a controller. `assertOk()` fails with `Expected response status code [200] but received 404.`; the draft-event test's own `assertNotFound()` happens to pass already, for the wrong reason (no route, not the visibility rule) — that is fine, it is re-asserted for the right reason once Step 3 lands.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Calendar\CalendarFile;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Slots\SlotRegistry;

// ...class docblock unchanged...
final class EventLandingController
{
    public function __construct(
        private readonly SlotRegistry $slots,
        private readonly BrandUrl $brandUrl,
    ) {}

    // ...index(), summarize(), show() unchanged until here...

    /**
     * Spec §6.2: public, no authentication — the only gate is the same
     * visibility rule `show()` enforces, so a draft event's calendar file
     * is as unreachable as its landing page.
     *
     * `LOCATION` carries the primary venue's name and full address (§6.1's
     * original wording): unlike Task 32's mail attachment, this route
     * reads Event's own `Venue` rows directly rather than a read-model
     * projection off `VenueAssigned`, so it is not limited to name-and-city
     * (§13's 2026-09-11 venue amendment covers the projection, not this).
     */
    public function calendar(Event $event): HttpResponse
    {
        abort_unless($event->isVisibleOnLanding(), 404);

        $venue = $event->primaryVenue() ?? $event->venues->first();

        $ics = CalendarFile::forEvent(
            uid: $event->getKey().'@'.$this->hostFor($event->brand),
            summary: $event->name,
            description: $event->description,
            location: $venue === null
                ? null
                : sprintf('%s, %s, %s %s', $venue->name, $venue->address_line1, $venue->postal_code, $venue->city),
            url: route('event.show', ['event' => $event->slug]),
            startsAt: $event->startsAtInstant(),
            endsAt: $event->endsAtInstant(),
            lastModifiedAt: $event->updated_at,
        );

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$event->slug.'.ics"',
        ]);
    }

    /**
     * `CalendarFile::forEvent()`'s `UID` is the event id plus the brand's
     * host (spec §6.1), so re-importing updates one entry rather than
     * duplicating it. Reads the brand's *primary* domain through
     * `BrandUrl` — the same service every other cross-module link in this
     * codebase uses to reach a brand's host from outside a request —
     * rather than `request()->getHost()`, so the identifier is the same
     * across a brand's several domains (apex, www) and across two visits
     * on different ones. Falls back to `APP_URL`'s host on a brand with no
     * domain configured yet, mirroring `RegistrationMailer::brandAbsolute()`'s
     * own fallback.
     */
    private function hostFor(Brand $brand): string
    {
        $origin = $this->brandUrl->baseFor($brand);

        return $origin === null
            ? (string) parse_url((string) config('app.url'), PHP_URL_HOST)
            : (string) parse_url($origin, PHP_URL_HOST);
    }
}
```

`show()`'s body is untouched by this task (Task 29 edits its `slots` line).

In `app-modules/event/routes/web/event.php`, append after the `event.show`
route:

```php
// The event's calendar file (spec §6.2): public, no authentication, the
// same visibility rule `show()` enforces. `.ics` sits in the URI itself,
// not a query string, so both a calendar app's "subscribe by URL" and a
// plain browser download just work.
Route::get('/events/{event:slug}/calendar.ics', [EventLandingController::class, 'calendar'])
    ->name('event.calendar');
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/EventCalendarRouteTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- event`

```bash
git add app-modules/event/src/Http/Controllers/EventLandingController.php app-modules/event/routes/web/event.php app-modules/event/tests/EventCalendarRouteTest.php
git commit -m "Add the public GET /events/{event:slug}/calendar.ics download, gated by the same visibility rule as the landing page (#72)."
```

---

### Task 29: `event.landing`'s calendar button — Event's own slot contribution

**Files:**
- Modify: `app-modules/event/src/Http/Controllers/EventLandingController.php`
  (the `'slots' => $this->slots->for('event.landing', ['eventId' => $event->getKey()])`
  line inside `show()` — line 114 before Task 28's edit; search for it by
  content, Task 28's insert shifts the number)
- Modify: `app-modules/event/src/Providers/EventServiceProvider.php`
  (add `use Konventix\Platform\Slots\SlotRegistry;` after line 17; insert
  `registerSlots()` after `registerNavigation()`, which ends line 106,
  before `registerConsoleEventSources()`, which starts line 108)
- Create: `app-modules/event/resources/js/components/CalendarLink.vue`
- Test: Create `app-modules/event/tests/EventLandingCalendarSlotTest.php`

**Interfaces:**
- Consumes: named route `event.calendar` (Task 28);
  `Konventix\Platform\Slots\SlotRegistry::add(string $slot, string $module, string $component, array|Closure $props = [], int $order = 100, ?string $feature = null): void`
  (existing, `app-modules/platform/src/Slots/SlotRegistry.php`).
- Produces: nothing a later task in this section consumes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Models\Event;

/**
 * Spec §7: Event's own contribution to `event.landing` — the landing page
 * has no action button today, so this is the first entry the slot ever
 * carries for a public visitor. No SSR process runs in this environment
 * (the same note `RegistrationMineSeatSlotTest.php` makes for Resources'
 * seat-picker link), so the rendered anchor itself is not assertable here
 * — `npm run build` and `npm run types:check` cover the template
 * compiling against this prop shape.
 */
function eventLandingCalendarSlot(AssertableInertia $page): ?array
{
    return collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'event/CalendarLink');
}

it('contributes a calendar link carrying the event slug', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($event): void {
            $slot = eventLandingCalendarSlot($page);

            expect($slot)->not->toBeNull()
                ->and($slot['module'])->toBe('event')
                ->and($slot['props']['eventSlug'])->toBe($event->slug);
        });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/EventLandingCalendarSlotTest.php`
Expected: FAIL with `Failed asserting that null is not null.` — `EventServiceProvider::registerSlots()` does not exist yet, so `event.landing` carries no `event/CalendarLink` entry and `eventLandingCalendarSlot()` returns null.

- [ ] **Step 3: Implement**

In `EventLandingController::show()`, widen the slot context so Event's own
contribution does not need a second query to re-fetch the event it already
has in scope:

```php
            'slots' => $this->slots->for('event.landing', ['eventId' => $event->getKey(), 'eventSlug' => $event->slug])
```

In `EventServiceProvider.php`, add the import and the new hook:

```php
use Konventix\Platform\Slots\SlotRegistry;
```

```php
    /**
     * Spec §7: the landing page has no action button today, so this is the
     * first entry `event.landing` carries. Styled after the seat picker's
     * primary-action link in Resources (`SeatSummarySlot.vue`'s "Choose
     * your seat"), so a visitor sees one consistent button treatment
     * across both public and account-page surfaces. No `feature:` gate:
     * Event is Kernel (`$gateable = false` above), never switched off.
     */
    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'event.landing',
            module: 'event',
            component: 'event/CalendarLink',
            props: static fn (array $context): array => [
                'eventSlug' => $context['eventSlug'],
            ],
            // After Registration's offers (order: 10, RegistrationServiceProvider)
            // — "add to calendar" is a secondary action once a visitor has
            // seen what is on sale, not the page's first call to action.
            order: 20,
        );
    }
```

New file `app-modules/event/resources/js/components/CalendarLink.vue`:

```vue
<script setup lang="ts">
import { CalendarPlus } from '@lucide/vue';
import { calendar } from '@/routes/event';

/**
 * Event's own contribution to `event.landing` (spec §7), registered by
 * `EventServiceProvider::registerSlots()`. Styling borrowed from the seat
 * picker's link in Resources (`SeatSummarySlot.vue`'s "Choose your seat"
 * button) — the landing page has no action button of its own to match.
 *
 * A plain anchor, not Inertia's `<Link>`: this points at a file download
 * (`Content-Disposition: attachment`), and an Inertia visit would try to
 * fetch it as a page instead of letting the browser save it — the same
 * reasoning `admin/orders/Show.vue` follows for its invoice PDF link.
 */
const props = defineProps<{ eventSlug: string }>();
</script>

<template>
    <a
        :href="calendar(props.eventSlug).url"
        class="inline-flex min-h-11 w-fit items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:bg-accent/90"
    >
        <CalendarPlus class="size-4" />
        Add to calendar
    </a>
</template>
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/event/tests/EventLandingCalendarSlotTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- event`

```bash
git add app-modules/event/src/Http/Controllers/EventLandingController.php app-modules/event/src/Providers/EventServiceProvider.php app-modules/event/resources/js/components/CalendarLink.vue app-modules/event/tests/EventLandingCalendarSlotTest.php
git commit -m "Add Event's calendar-button contribution to the event.landing slot, styled after the seat picker's link (#72)."
```

---

### Task 30: `AttachmentData` gains an inline form

**Files:**
- Modify: `app-modules/messaging/src/Data/AttachmentData.php` (whole file, 25 lines)
- Test: Create `app-modules/messaging/tests/AttachmentDataTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Konventix\Messaging\Data\AttachmentData::__construct(string $as, ?string $disk = null, ?string $path = null, ?string $content = null, ?string $mime = null)`
  and `AttachmentData::MAX_INLINE_BYTES` (65536), for Task 31's `TemplatedMail::attachments()`
  and Task 32's confirmation-mail attachment. `Commerce\Actions\SendInvoiceMail`
  (`app-modules/commerce/src/Actions/SendInvoiceMail.php:103-109`) already calls
  the disk form entirely by named argument and needs no change.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Messaging\Data\AttachmentData;

/**
 * Spec §6.3: the inline form exists so a calendar file — derived, tiny,
 * regenerable — never touches a disk, while the disk form (Commerce's
 * invoice) stays the only way to attach a document. Exactly one form must
 * be set; the constructor is the enforcement point, not a caller
 * convention, so a half-specified attachment fails here rather than only
 * once `TemplatedMail::attachments()` tries to read it.
 */
it('accepts the disk form alone', function (): void {
    $attachment = new AttachmentData(as: 'invoice.pdf', disk: 'invoices', path: 'invoices/INV-1.pdf');

    expect($attachment->disk)->toBe('invoices')
        ->and($attachment->path)->toBe('invoices/INV-1.pdf')
        ->and($attachment->content)->toBeNull();
});

it('accepts the inline form alone', function (): void {
    $attachment = new AttachmentData(as: 'event.ics', content: 'BEGIN:VCALENDAR', mime: 'text/calendar');

    expect($attachment->content)->toBe('BEGIN:VCALENDAR')
        ->and($attachment->disk)->toBeNull()
        ->and($attachment->path)->toBeNull();
});

it('refuses both the disk form and the inline form at once', function (): void {
    new AttachmentData(as: 'event.ics', disk: 'invoices', path: 'invoices/INV-1.pdf', content: 'BEGIN:VCALENDAR');
})->throws(InvalidArgumentException::class);

it('refuses neither form being set', function (): void {
    new AttachmentData(as: 'event.ics');
})->throws(InvalidArgumentException::class);

it('refuses inline content over the 64 KiB ceiling', function (): void {
    new AttachmentData(as: 'event.ics', content: str_repeat('a', AttachmentData::MAX_INLINE_BYTES + 1));
})->throws(InvalidArgumentException::class);

it('accepts inline content exactly at the 64 KiB ceiling', function (): void {
    $attachment = new AttachmentData(as: 'event.ics', content: str_repeat('a', AttachmentData::MAX_INLINE_BYTES));

    expect(strlen($attachment->content))->toBe(AttachmentData::MAX_INLINE_BYTES);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/messaging/tests/AttachmentDataTest.php`
Expected: FAIL on most of the its, for two distinct reasons — today's constructor is `(string $disk, string $path, string $as, ?string $mime = null)`. Every it passing `content:` fails with `Error: Unknown named parameter $content`; `'refuses inline content over the 64 KiB ceiling'` and the ceiling-boundary it fail earlier still, with `Error: Undefined constant Konventix\Messaging\Data\AttachmentData::MAX_INLINE_BYTES`; `'refuses neither form being set'` fails its `->throws(InvalidArgumentException::class)` because the actual failure is `ArgumentCountError` (today's `$disk`/`$path` are required), not `InvalidArgumentException`. `'accepts the disk form alone'` passes unmodified already — expected, TDD only asks that the its exercising the new behaviour fail for the right reason.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * One attachment on an outbound message, in one of two forms.
 *
 * The disk form (`disk` + `path`) is the original contract: the queued job
 * payload stays small, and the file is read at send time by the channel.
 * The first consumer is Commerce's invoice mail attaching the archived
 * ZUGFeRD PDF (ADR-0031: what the recipient gets is the sealed archive
 * copy, byte-identical) — that call site is unchanged by the form below.
 *
 * The inline form (`content`) exists for a file that is derived, tiny and
 * regenerable — the calendar file Event's public route and Registration's
 * confirmation mail both produce (spec §6.3) — where writing it to a disk
 * would buy a cache-invalidation problem for nothing. `as` leads the
 * constructor because it is the only field both forms need, and every call
 * site uses named arguments (`Commerce\Actions\SendInvoiceMail` already
 * does).
 *
 * Exactly one form must be set: both or neither is refused here, in the
 * constructor, so a caller cannot build a half-specified attachment that
 * only fails once `TemplatedMail::attachments()` tries to read it. Inline
 * content is capped at `MAX_INLINE_BYTES` so the disk form stays the only
 * way to attach a document — nobody grows a queued job payload by
 * attaching a PDF this way.
 */
final class AttachmentData extends Data
{
    public const int MAX_INLINE_BYTES = 64 * 1024;

    public function __construct(
        public readonly string $as,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        public readonly ?string $content = null,
        public readonly ?string $mime = null,
    ) {
        $diskForm = $this->disk !== null || $this->path !== null;
        $inlineForm = $this->content !== null;

        if ($diskForm && $inlineForm) {
            throw new InvalidArgumentException(
                'AttachmentData must set either a disk and path, or inline content — not both.',
            );
        }

        if (! $diskForm && ! $inlineForm) {
            throw new InvalidArgumentException(
                'AttachmentData must set either a disk and path, or inline content.',
            );
        }

        if ($diskForm && ($this->disk === null || $this->path === null)) {
            throw new InvalidArgumentException(
                "AttachmentData's disk form needs both disk and path.",
            );
        }

        if ($inlineForm && strlen($this->content) > self::MAX_INLINE_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'AttachmentData inline content is %d bytes, over the %d KiB ceiling.',
                strlen($this->content),
                self::MAX_INLINE_BYTES / 1024,
            ));
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/messaging/tests/AttachmentDataTest.php`

- [ ] **Step 5: Verify and commit**

`AttachmentData` carries no `#[TypeScript]` attribute (it is never sent as
an Inertia prop), so this regeneration is expected to produce no diff —
run it anyway, because the instruction is unconditional on any Data class
change and a stale manifest from unrelated work on this branch would
otherwise go unnoticed:

```bash
printf '{\n    "generated.d.ts": "0"\n}\n' > /home/mawiguko/git/konventix/konventix-poc/resources/js/types/typescript-transformer-manifest.json
```

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer types:generate`
Run: `npx prettier --write resources/js/types`
Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- messaging`

```bash
git add app-modules/messaging/src/Data/AttachmentData.php app-modules/messaging/tests/AttachmentDataTest.php
git status --short resources/js/types
# only `git add` the files under resources/js/types that actually show a diff
git commit -m "Give AttachmentData an inline content form alongside the disk form, refusing both and neither (#72)."
```

---

### Task 31: `TemplatedMail::attachments()` honours the inline form

**Files:**
- Modify: `app-modules/messaging/src/Mail/TemplatedMail.php` (lines 61-75)
- Test: Create `app-modules/messaging/tests/TemplatedMailTest.php`

**Interfaces:**
- Consumes: `Konventix\Messaging\Data\AttachmentData` (Task 30);
  `Illuminate\Mail\Mailables\Attachment::fromData(Closure $data, ?string $name = null): static`
  and `::fromStorageDisk(?string $disk, string $path): static` (Laravel core,
  unchanged).
- Produces: `TemplatedMail::attachments(): array<int, Attachment>` now
  building either form, which Task 32's confirmation mail relies on
  end-to-end (`SendMessage` → `SendQueuedMessage` → `MailChannel` →
  `new TemplatedMail(..., fileAttachments: ...)`, already wired
  unconditionally in `MailChannel::send()` — no change needed there).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Mail\TemplatedMail;

/**
 * Spec §6.3: `attachments()` builds an in-memory `Attachment::fromData()`
 * for the inline form and keeps `fromStorageDisk()` for the disk form.
 * `InvoiceMailTest.php` already exercises the disk form end to end through
 * `SendInvoiceMail` and must keep passing unchanged; this file covers
 * `TemplatedMail` directly, for both forms.
 */
function templatedMail(array $attachments): TemplatedMail
{
    return new TemplatedMail(
        messageSubject: 'Subject',
        htmlBody: '<p>Body</p>',
        textBody: 'Body',
        fromAddress: 'noreply@example.test',
        fromName: 'Konventix',
        replyToAddress: null,
        fileAttachments: $attachments,
    );
}

it('resolves an inline attachment through the data strategy, carrying the given name and mime type', function (): void {
    $mail = templatedMail([
        new AttachmentData(as: 'event.ics', content: 'BEGIN:VCALENDAR', mime: 'text/calendar'),
    ]);

    $attachment = $mail->attachments()[0];

    // attachWith() is the same public entry point Attachment::attachTo()
    // uses internally when a real mail is sent — invoking it directly here
    // resolves the closure without needing a configured mailer or a real
    // storage disk.
    $resolved = $attachment->attachWith(
        pathStrategy: fn (string $path): string => $path,
        dataStrategy: fn (Closure $data): string => $data(),
    );

    expect($resolved)->toBe('BEGIN:VCALENDAR')
        ->and($attachment->as)->toBe('event.ics')
        ->and($attachment->mime)->toBe('text/calendar');
});

it('still builds a disk attachment, unchanged, for the disk form', function (): void {
    $mail = templatedMail([
        new AttachmentData(as: 'invoice.pdf', disk: 'invoices', path: 'invoices/INV-1.pdf', mime: 'application/pdf'),
    ]);

    $attachments = $mail->attachments();

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->as)->toBe('invoice.pdf')
        ->and($attachments[0]->mime)->toBe('application/pdf');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/messaging/tests/TemplatedMailTest.php`
Expected: FAIL on the first it — `attachments()` still calls
`Attachment::fromStorageDisk($attachment->disk, $attachment->path)`
unconditionally, and the inline `AttachmentData` leaves both null. Calling
`attachWith()`'s `dataStrategy` runs `$storage->get(null)`
(`Illuminate\Filesystem\FilesystemAdapter::get()` → League Flysystem's
`read(string $path)`), which throws a `TypeError` (`Argument #1 ($path)
must be of type string, null given`) because `read()`'s parameter is
non-nullable. The second it passes unmodified already.

- [ ] **Step 3: Implement**

```php
    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map($this->buildAttachment(...), $this->fileAttachments);
    }

    private function buildAttachment(AttachmentData $attachment): Attachment
    {
        if ($attachment->content !== null) {
            $content = $attachment->content;
            $built = Attachment::fromData(fn (): string => $content, $attachment->as);
        } else {
            $built = Attachment::fromStorageDisk($attachment->disk, $attachment->path)->as($attachment->as);
        }

        return $attachment->mime === null ? $built : $built->withMime($attachment->mime);
    }
```

This replaces the existing `attachments()` method (lines 61-75); every other
method on `TemplatedMail` is unchanged. `$content` is captured into a local
variable before the closure, not read from `$attachment->content` inside
it, so phpstan sees a non-nullable `string` going into a closure declared
`fn (): string`.

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/messaging/tests/TemplatedMailTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- messaging commerce`

(Commerce, because `InvoiceMailTest.php`'s existing attachment assertion
must keep passing unchanged — `contrib/verify-plan.sh`'s mapping runs each
named module's own tests.)

```bash
git add app-modules/messaging/src/Mail/TemplatedMail.php app-modules/messaging/tests/TemplatedMailTest.php
git commit -m "Make TemplatedMail::attachments() build an in-memory attachment for the inline form and keep fromStorageDisk for the disk form (#72)."
```

---

### Task 32: The confirmation mail attaches the event's calendar file

**Files:**
- Modify: `app-modules/registration/src/Mail/RegistrationMailer.php` (imports
  lines 1-19; `send()`, lines 44-60; new `calendarAttachment()` inserted after
  `cartFields()`, which ends line 136; new `wallClockToInstant()` and
  `hostFor()` inserted after `brandAbsolute()`, which ends line 160)
- Modify: `app-modules/registration/src/Listeners/MailOnRegistrationConfirmed.php`
  (imports lines 1-11; `handle()` body, lines 49-55)
- Test: Modify `app-modules/registration/tests/RegistrationMailsTest.php`
  (insert after line 161, the end of the first `it()` block)

**Interfaces:**
- Consumes: `Konventix\Platform\Calendar\CalendarFile::forEvent(...)` (top of
  this section); `Konventix\Messaging\Data\AttachmentData::__construct(string $as, ?string $disk = null, ?string $path = null, ?string $content = null, ?string $mime = null)`
  (Task 30); `registration_event_summaries`'s `starts_at`, `ends_at`,
  `timezone`, `venue_label` columns via `Konventix\Registration\Models\EventSummary`
  — built by an earlier, out-of-scope task extending `ProjectEventSummary`
  per §13's 2026-09-11 amendment; this task only reads them, with a null
  guard for a summary row that predates them.
- Produces: `RegistrationMailer::send(Registration $registration, string $templateKey, Data $payload, ?DataCollection $attachments = null): void`
  and `RegistrationMailer::calendarAttachment(Registration $registration): ?AttachmentData`,
  consumed only by `MailOnRegistrationConfirmed`; no later task in this plan
  section depends on this.

- [ ] **Step 1: Write the failing test**

```php
/**
 * Spec §6.3 (§13's 2026-09-11 amendment): the confirmation mail is the one
 * place Registration attaches a calendar file, built from its own
 * `registration_event_summaries` projection through the shared
 * `CalendarFile` encoder in Platform — Registration may not import Event
 * (ADR-0004), so it never touches `Konventix\Event\Models\Event`.
 * `registrationTypeEvent()` (tests/Helpers.php) is the module's existing,
 * documented way to seed an `EventSummary` row: it fires `EventCreated`
 * rather than inventing one, and `EventCreated` already carries the
 * `startsAt`/`endsAt`/`timezone` the extended projector copies across.
 */
it('attaches the event calendar file to the confirmation mail, named for the event', function (): void {
    $brand = $this->withBrand();
    $registrant = regMailsRecipient($brand->id);
    Queue::fake();

    $eventId = registrationTypeEvent($brand);
    $type = RegistrationType::factory()->create(['event_id' => $eventId]);

    app(SubmitRegistration::class)->handle($type, $registrant->id, regMailsFreeSubmission());

    $confirmed = regMailsPushed('registration.confirmed');
    $attachments = $confirmed[0]->message->attachments;

    expect($attachments)->not->toBeNull()
        ->and($attachments->items())->toHaveCount(1);

    $attachment = $attachments->items()[0];

    expect($attachment->as)->toBe('sxlan-2027.ics')
        ->and($attachment->mime)->toBe('text/calendar')
        ->and($attachment->content)->toContain('BEGIN:VCALENDAR')
        ->and($attachment->content)->toContain('SUMMARY:SXLan 2027');
});
```

Insert this `it()` into `RegistrationMailsTest.php` right after the existing
`'sends only registration.confirmed, not registration.submitted, ...'` test
(the file's first `it()`, ending line 161).

- [ ] **Step 2: Run it to verify it fails**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/RegistrationMailsTest.php --filter="attaches the event calendar file"`
Expected: FAIL with `Failed asserting that null is not null.` —
`RegistrationMailer::send()` never sets `attachments` today, so the queued
`SendMessageData::$attachments` is null.

- [ ] **Step 3: Implement**

In `RegistrationMailer.php`, add to the import block:

```php
use Illuminate\Support\Carbon;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Platform\Calendar\CalendarFile;
use Spatie\LaravelData\DataCollection;
```

(`Illuminate\Support\Facades\Log`, `...Route`, `BrandUrl`, `Brand`, `Data`
and the rest stay; Pint reorders the block, so exact placement here does
not matter.)

Widen `send()`:

```php
    public function send(Registration $registration, string $templateKey, Data $payload, ?DataCollection $attachments = null): void
    {
        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: $registration->registrant_user_id,
                templateKey: $templateKey,
                payload: $payload,
                brandId: $registration->brand_id,
                attachments: $attachments,
            ));
        } catch (RuntimeException $exception) {
            Log::warning('Registration lifecycle mail could not be sent.', [
                'registration_id' => $registration->id,
                'template_key' => $templateKey,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
```

`$attachments` defaults to null and is appended after the existing three
parameters, so the four other `RegistrationMailer::send()` call sites
(`MailOnRegistrationSubmitted`, `...Approved`, `...Rejected`,
`...Cancelled`) need no change.

Add, after `cartFields()`:

```php
    /**
     * The event's calendar file (spec §6.3, §13's 2026-09-11 amendment),
     * attached to the confirmation mail only — `MailOnRegistrationConfirmed`
     * is the one caller. Built from this module's own `EventSummary` read
     * model (ADR-0004: Registration may not import Event) through the
     * shared encoder every module reaches the way they reach
     * `MediaLibrary`: `CalendarFile::forEvent()`.
     *
     * Null when Registration has not projected a summary for this event,
     * or that summary predates the columns this needs — a confirmation
     * mail with no calendar file is the existing behaviour, not a new
     * failure mode, the same shape as `SendInvoiceMail`'s missing-document
     * guard.
     *
     * `description` is always null here: `EventSummary` carries no
     * description column, unlike `EventLandingController::calendar()`'s
     * fuller call, which reads `Event::$description` directly.
     * `location` is `venue_label` as projected — name and city only, what
     * `VenueAssigned`'s contract carries (§13's venue amendment) — narrower
     * than the public route's full address.
     */
    public function calendarAttachment(Registration $registration): ?AttachmentData
    {
        $summary = EventSummary::withoutBrandScope()
            ->where('brand_id', $registration->brand_id)
            ->where('event_id', $registration->event_id)
            ->first();

        if ($summary === null || $summary->starts_at === null || $summary->ends_at === null || $summary->timezone === null) {
            return null;
        }

        $brand = Brand::query()->find($registration->brand_id);

        $ics = CalendarFile::forEvent(
            uid: $summary->event_id.'@'.$this->hostFor($brand),
            summary: $summary->name,
            description: null,
            location: $summary->venue_label,
            url: $brand === null || ! Route::has('event.show')
                ? null
                : $this->brandAbsolute($registration, route('event.show', ['event' => $summary->slug], absolute: false)),
            startsAt: $this->wallClockToInstant($summary->starts_at, $summary->timezone),
            endsAt: $this->wallClockToInstant($summary->ends_at, $summary->timezone),
            lastModifiedAt: $summary->updated_at,
        );

        return new AttachmentData(as: $summary->slug.'.ics', content: $ics, mime: 'text/calendar');
    }
```

Add, after `brandAbsolute()`:

```php
    /**
     * Mirrors `Event::wallClockToInstant()` — a private helper on Event's
     * own model this module may not call (ADR-0004) — on a local copy of
     * the columns: `EventSummary`'s `starts_at`/`ends_at` are wall clock
     * plus an IANA zone (ADR-0027), projected verbatim from the same
     * `EventCreated`/`EventRescheduled` payload fields Event itself
     * stores.
     */
    private function wallClockToInstant(Carbon $wallClock, string $timezone): Carbon
    {
        return Carbon::parse($wallClock->format('Y-m-d H:i:s'), $timezone);
    }

    /**
     * `CalendarFile::forEvent()`'s `UID` is the event id plus the brand's
     * host (spec §6.1) — the same primary-domain reasoning
     * `EventLandingController::hostFor()` uses for the public route, so
     * the two surfaces agree on one host for the same brand and a calendar
     * app re-importing either file updates the same entry.
     */
    private function hostFor(?Brand $brand): string
    {
        $origin = $brand === null ? null : $this->brandUrl->baseFor($brand);

        return $origin === null
            ? (string) parse_url((string) config('app.url'), PHP_URL_HOST)
            : (string) parse_url($origin, PHP_URL_HOST);
    }
```

In `MailOnRegistrationConfirmed.php`, add to the imports:

```php
use Konventix\Messaging\Data\AttachmentData;
use Spatie\LaravelData\DataCollection;
```

Replace the `handle()` body from `$cart = ...` onward:

```php
        $cart = $this->mailer->cartFields($registration);
        $calendarAttachment = $this->mailer->calendarAttachment($registration);

        $this->mailer->send(
            $registration,
            'registration.confirmed',
            new RegistrationMailData(
                ...$this->mailer->commonFields($registration),
                cartUrl: $cart['cartUrl'],
                cartHoldExpiresAt: $cart['cartHoldExpiresAt'],
            ),
            attachments: $calendarAttachment === null ? null : new DataCollection(AttachmentData::class, [$calendarAttachment]),
        );
```

- [ ] **Step 4: Run it to verify it passes**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/registration/tests/RegistrationMailsTest.php`

- [ ] **Step 5: Verify and commit**

Run: `flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail composer verify -- registration`

```bash
git add app-modules/registration/src/Mail/RegistrationMailer.php app-modules/registration/src/Listeners/MailOnRegistrationConfirmed.php app-modules/registration/tests/RegistrationMailsTest.php
git commit -m "Attach the event's calendar file to the registration confirmation mail, built from Registration's own event summary (#72)."
```
### Task 33: Observability — the tickets-and-calendar dashboard row

**Files:**
- Modify: `app-modules/admission/src/Metrics/AdmissionMetrics.php` (created by Task 10; already carries `admission_tokens_superseded_total`). **Append the new definition; do not retype the existing one** — its `help` string is Task 10's and must survive verbatim.
- Create: `app-modules/admission/src/Http/Middleware/RecordTicketRenderMetrics.php`
- Modify: `app-modules/admission/src/Http/Controllers/TicketDownloadController.php` (created by Task 23)
- Modify: `app-modules/admission/routes/web/admission.php`
- Modify: `app-modules/admission/tests/AdmissionMetricsTest.php` (created by Task 10)
- Create: `app-modules/event/src/Metrics/EventMetrics.php`
- Modify: `app-modules/event/src/Providers/EventServiceProvider.php`
- Create: `app-modules/event/src/Http/Middleware/RecordCalendarDownloadMetrics.php`
- Modify: `app-modules/event/routes/web/event.php`
- Create: `app-modules/event/tests/EventMetricsTest.php`
- Modify: `contrib/observability/src/sale.mjs`
- Modify: `contrib/observability/dashboards/konventix-sale.json` (generated, never hand-edited)
- Test: `tests/Feature/ObservabilityCoverageTest.php` (existing — verifies coverage, not modified)

**Interfaces:**
- Consumes: `Konventix\Platform\Observability\Metrics::increment(string $name, array $labels = [], float $by = 1.0)`; `Konventix\Platform\Observability\{MetricDefinition,MetricKind,MetricCatalog}`; `Konventix\Platform\Brands\BrandContext::current(): ?Brand`; `Konventix\Platform\Brands\BrandSlugs::UNKNOWN`; the ticket routes `GET /my/registrations/{registration}/ticket` (`TicketDownloadController::buyer`) and `GET /my/admissions/{admission}/ticket` (`TicketDownloadController::holder`), each producing a PDF from `Collection<int, Admission> $admissions`; the calendar route `GET /events/{event:slug}/calendar.ics` (`EventLandingController::calendar`, an earlier task); `admission_tokens_superseded_total{brand}` already registered and incremented by Task 7's `VerifyAdmissionToken` on `CheckInRejectionReason::NonceSuperseded`.
- Produces: `admission_tickets_rendered_total{brand}`, `event_calendar_downloads_total{brand}` — both counters, both declared and recorded; the `konventix-sale` dashboard's new "Tickets and calendars" row, which is what Task 37's evidence post on #72 points at for the machine-readable acceptance criterion.

- [ ] **Step 1: Add `admission_tickets_rendered_total` to Admission's metric catalogue**
Task 10 already returns `admission_tokens_superseded_total` from `AdmissionMetrics::definitions()`. Add the second entry to the same array — `register()` in `AdmissionServiceProvider::registerMetrics()` spreads the whole array, so no provider change is needed:
```php
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            // Task 7 (ADR-0059 §5, design §9): incremented by VerifyAdmissionToken
            // when a scan is refused for a superseded nonce.
            new MetricDefinition('admission_tokens_superseded_total', MetricKind::Counter, 'Scans refused because the presented token carried a superseded nonce.', ['brand']),

            // Task 33 (design §9): one increment per admission page a ticket
            // route actually served, recorded by
            // Http\Middleware\RecordTicketRenderMetrics.
            new MetricDefinition('admission_tickets_rendered_total', MetricKind::Counter, 'Ticket PDF pages rendered and served.', ['brand']),
        ];
    }
```

- [ ] **Step 2: Create the ticket-render middleware**
An HTTP-triggered fact, not a domain-state change, so it follows `App\Http\Middleware\RecordHttpMetrics` and Commerce's `Http\Middleware\RecordWebhookMetrics` rather than a `Listeners\Metrics\*` recorder on an event nothing else needs. The design's §9 counts *pages*, not requests — a buyer's file bundles one page per admission in a single response — so the controller stamps the page count onto the request; the middleware defaults to 1 when nothing was stamped, which only matters if this middleware is ever attached somewhere the controller does not set it.
```php
<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every ticket PDF actually served (ADR-0059, design §9): one
 * increment per rendered page, not per request, because a buyer's file
 * bundles one page per admission into a single response. The controller
 * stamps the page count onto the request as an attribute right before it
 * returns the PDF — the same before/after shape `RecordWebhookMetrics` and
 * the host's `RecordHttpMetrics` use, adapted because this counter's unit
 * is pages, not requests. Wired only onto the two PDF routes
 * (`forRegistration`, `show`); `/my/tickets` is a listing page, not a
 * render, and carries no attribute to read.
 *
 * A render failure is a 500, already covered by `KonventixHttp5xxRate` and
 * `KonventixErrorsLogged` (ADR-0059 point 8, design §9) — this counter only
 * fires on success, so it never has to distinguish an error from a render.
 */
final class RecordTicketRenderMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 300) {
            $pages = (int) $request->attributes->get('konventix_ticket_pages', 1);
            $brand = app(BrandContext::class)->current();

            app(Metrics::class)->increment(
                'admission_tickets_rendered_total',
                ['brand' => $brand?->slug ?? BrandSlugs::UNKNOWN],
                (float) max(1, $pages),
            );
        }

        return $response;
    }
}
```

- [ ] **Step 3: Wire the middleware onto the two PDF routes and stamp the page count**

Tasks 23, 24 and 26 already created the controllers and declared every route. **Do not redeclare them here** — this step adds one line to each of two methods and attaches one middleware to two existing route declarations.

In `Konventix\Admission\Http\Controllers\TicketDownloadController`, immediately before the response is built, stamp how many pages the render actually produced. In `buyer()`:

```php
$request->attributes->set('konventix_ticket_pages', $admissions->count());
```

and in `holder()`, where exactly one admission is rendered:

```php
$request->attributes->set('konventix_ticket_pages', 1);
```

In `app-modules/admission/routes/web/admission.php`, add the import and chain the middleware onto the two declarations that already exist. Leave `admission.tickets.mine` alone: listing tickets renders no PDF.

```php
use Konventix\Admission\Http\Middleware\RecordTicketRenderMetrics;
```

```php
// on the existing admission.tickets.buyer declaration:
    ->middleware(RecordTicketRenderMetrics::class)

// and on the existing admission.tickets.holder declaration:
    ->middleware(RecordTicketRenderMetrics::class)
```

- [ ] **Step 4: Give Event its first metric — a catalogue and its wiring**
Event has no `src/Metrics/` today. Create the catalogue:
```php
<?php

declare(strict_types=1);

namespace Konventix\Event\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Event's entries in the metric catalogue (ADR-0059, design §9). One
 * counter today — the calendar file has no gauge or histogram — recorded
 * by `Http\Middleware\RecordCalendarDownloadMetrics` on the public
 * download route, not by a listener: a download is an HTTP fact, not a
 * domain-state change, and no event exists for it.
 */
final class EventMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('event_calendar_downloads_total', MetricKind::Counter, 'Public .ics calendar file downloads.', ['brand']),
        ];
    }
}
```
Wire it into `EventServiceProvider`, matching Resources'/Commerce's/Messaging's own `registerMetrics()` pattern:
```php
use Konventix\Event\Metrics\EventMetrics;
use Konventix\Platform\Observability\MetricCatalog;

// ...

public function boot(): void
{
    parent::boot();

    $this->registerMetrics();

    if ($this->app->runningInConsole()) {
        $this->commands([ReplayEventDetailsCommand::class]);
    }
}

/**
 * Event's one metric (ADR-0059, design §9): the public calendar download
 * counter. The host never learns this exists.
 */
protected function registerMetrics(): void
{
    $this->app->make(MetricCatalog::class)->register(...EventMetrics::definitions());
}
```

- [ ] **Step 5: Create the calendar-download middleware and wire it onto the public route**
```php
<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every public calendar-file download (ADR-0059, design §9): one per
 * successful response to `GET /events/{event:slug}/calendar.ics`, following
 * `RecordWebhookMetrics`'s before/after shape. A 404 (an event that is not
 * publicly visible, design §6.2) is not a download and is not counted.
 */
final class RecordCalendarDownloadMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 300) {
            $brand = app(BrandContext::class)->current();

            app(Metrics::class)->increment(
                'event_calendar_downloads_total',
                ['brand' => $brand?->slug ?? BrandSlugs::UNKNOWN],
            );
        }

        return $response;
    }
}
```
In `app-modules/event/routes/web/event.php`, Task 28 already declared the `event.calendar` route. **Do not redeclare it** — add the import and chain the middleware onto the declaration that is already there:
```php
use Konventix\Event\Http\Middleware\RecordCalendarDownloadMetrics;
```
```php
// on the existing event.calendar declaration:
    ->middleware(RecordCalendarDownloadMetrics::class)
```

- [ ] **Step 6: The dashboard row**
Add a "Tickets and calendars" row to `contrib/observability/src/sale.mjs`, holding all three metrics per design §9 — the two this task records and `admission_tokens_superseded_total` from Task 10, whose registration this task does not touch:
```js
        row('Tickets and calendars', [
            stat('Tickets rendered', [prom(`sum(increase(konventix_admission_tickets_rendered_total{${J}}[$__range]))`)], { description: 'Ticket PDF pages served in the selected range.' }),
            stat('Calendar downloads', [prom(`sum(increase(konventix_event_calendar_downloads_total{${J}}[$__range]))`)]),
            stat('Tokens superseded', [prom(`sum(increase(konventix_admission_tokens_superseded_total{${J}}[$__range]))`)], { description: 'Scans refused for a nonce that was rotated since — reissues behaving as designed, not paged (ADR-0061).' }),
            timeseries('Tickets and calendars per hour', [
                prom(`sum by (brand) (increase(konventix_admission_tickets_rendered_total{${J}}[1h]))`, '{{brand}} tickets'),
                prom(`sum by (brand) (increase(konventix_event_calendar_downloads_total{${J}}[1h]))`, '{{brand}} calendars'),
            ], { w: 12 }),
            timeseries('Tokens superseded per hour by brand', [prom(`sum by (brand) (increase(konventix_admission_tokens_superseded_total{${J}}[1h]))`, '{{brand}}')], { w: 12 }),
        ]),
```
Then regenerate the committed JSON:
```bash
npm run observability:build
```
**No alert.** Nothing here can fail silently: a render or a download failure is a plain 500, already caught by `KonventixHttp5xxRate` and `KonventixErrorsLogged` (ADR-0059 point 8); a superseded-nonce refusal is a `CheckInRejectionReason` the person standing at the door sees immediately, not a background condition anyone needs paging for. `ObservabilityCoverageTest` enforces the mechanical half — every declared metric on a dashboard, every referenced metric declared, `/metrics` renders nothing undeclared — and does not expect a rule for a metric this task does not add one for.

- [ ] **Step 7: Extend the metric feature tests**
`AdmissionMetricsTest.php` (Task 10) already asserts `admission_tokens_superseded_total`. Add a case for the new one, following `WebhookMetricsTest`'s probe-route shape:
```php
it('counts ticket pages served, once per rendered response', function (): void {
    Route::middleware(RecordTicketRenderMetrics::class)
        ->get('/probe/ticket', function (): Response {
            request()->attributes->set('konventix_ticket_pages', 3);

            return response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
        });

    $this->get('/probe/ticket')->assertOk();

    expect(metricsRendered())->toContain('konventix_admission_tickets_rendered_total{brand="'.BrandSlugs::of($this->brand->getKey()).'"} 3');
});
```
Create `app-modules/event/tests/EventMetricsTest.php` fresh, mirroring the same shape for `event_calendar_downloads_total` against a probe route.

- [ ] **Final step: Verify and commit**
```bash
flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact app-modules/admission/tests/AdmissionMetricsTest.php app-modules/event/tests/EventMetricsTest.php tests/Feature/ObservabilityCoverageTest.php
git add app-modules/admission/src/Metrics/AdmissionMetrics.php app-modules/admission/src/Http/Middleware/RecordTicketRenderMetrics.php app-modules/admission/src/Http/Controllers/TicketDownloadController.php app-modules/admission/routes/web/admission.php app-modules/admission/tests/AdmissionMetricsTest.php app-modules/event/src/Metrics/EventMetrics.php app-modules/event/src/Providers/EventServiceProvider.php app-modules/event/src/Http/Middleware/RecordCalendarDownloadMetrics.php app-modules/event/routes/web/event.php app-modules/event/tests/EventMetricsTest.php contrib/observability/src/sale.mjs contrib/observability/dashboards/konventix-sale.json
git commit -m "Add the ticket-render, calendar-download and superseded-token counters to the Sale dashboard (#72)"
```

---

### Task 34: ADR-0061 — the decision record

**Files:**
- Create: `docs/decisions/0061-the-ticket-is-a-rendered-admission-document.md`

**Interfaces:**
- Consumes: the design doc's §2.1 (the gap), §5 (token stability), §6.3 (the `AttachmentData` narrowing), §8a; `IssueAdmissionToken::current()`/`::rotate()`; `admissions.token_nonce_hash`/`token_issued_at`; `CheckInRejectionReason::NonceSuperseded`; `AttachmentData(string $as, ?string $disk = null, ?string $path = null, ?string $content = null, ?string $mime = null)`; `Konventix\Platform\Documents\DocumentWatermark`; `Konventix\Platform\Calendar\CalendarFile::forEvent(...)`.
- Produces: the ADR every module's `SPEC.md` in Task 35 cites; the record Task 37's evidence post references for why the machine-readable-code acceptance criterion is covered the way it is.

- [ ] **Step 1: Write the ADR**
```markdown
# ADR-0061: The ticket is a rendered Admission document, and its token is stable

**Status:** accepted (2026-09-11, design at
docs/superpowers/specs/2026-09-11-tickets-and-calendar-design.md; GitLab #72,
related #68, #98, #23)

## Context

GitLab #72 asks for a participant-facing ticket and a calendar file; #68 is
the environment ribbon whose watermark criterion named a "ticket PDF" before
one existed, and #98 is the entrance scan the ticket's code has to keep
working with. Designing #72 found, in the design's §2.1, that the KVX1 token
was never actually stable: `IssueAdmissionToken`, `AdmissionTokenPayload` and
`IssueBadge` all say in their docblocks that reissuing a token strands the
previously printed QR, but no nonce was ever stored on `admissions`, neither
`RecordScan` nor `VerifyAdmissionToken` compared one, and
`CheckInRejectionReason::NonceSuperseded` existed but was referenced nowhere.
Every token ever minted for an admission stayed valid for its full 60 days.
Only an operator printing a badge exercised this path, so it went unnoticed;
a participant downloading their own ticket makes it live, because every
download would otherwise mint another equally-live credential with no way to
retire the last one.

Three questions had to be settled together, because the answer to each
shapes the other two: what a "ticket" is relative to the credential that
already exists, whether promising token stability is safe now that a
participant — not just an operator — can trigger a render, and how a
half-kilobyte generated calendar file reaches a mail whose attachment
contract (`AttachmentData`) was deliberately built to keep a queued payload
small.

## Decision

1. **A ticket is not a new credential.** It is
   `Konventix\Admission\Documents\TicketDocumentRenderer::render(Collection
   $admissions): string`, rendering the admission's existing KVX1 token — the
   same one `RecordScan`/`VerifyAdmissionToken` already read, the same one a
   badge print already uses — onto an A4 document, one page per admission,
   watermarked by the new `Konventix\Platform\Documents\DocumentWatermark`
   service (`::html(...)`, `::testSystemBanner()`). No table gains a
   "ticket" row, no new token format exists, and the door's decision logic
   does not change: a ticket is a *rendering* of an access right already
   granted, not a second thing that could disagree with it. This is why the
   design's §8 keeps the ticket inside Admission rather than a new module —
   the credential and its printed form share one owner.

2. **The token is now stable, and a superseded one is refused — this changes
   a security property.** `admissions` gains `token_nonce_hash`
   (HMAC-SHA256, a dedicated pepper, the plaintext nonce never stored, per
   LanCore's TKT-F-018) and `token_issued_at`. `IssueAdmissionToken::
   current()` returns the admission's existing nonce when one is stored and
   mints one only the first time; `IssueAdmissionToken::rotate()` mints a
   fresh nonce and writes a `token_superseded` delta entry. `handle()` is
   gone — a caller must now say which of the two it means, rather than
   reaching a single door that, until this ADR, could not have told the
   difference. `VerifyAdmissionToken` compares a presented token's nonce
   hash against the admission's stored one and refuses a mismatch with the
   existing `CheckInRejectionReason::NonceSuperseded`; an admission with no
   stored hash — everything minted before this deploy, and every admission
   an offline device has never seen — accepts any otherwise-valid token, so
   the rollout invalidates nothing already in circulation and a fresh
   scanner never closes a door on a whole queue. Before this ADR, "a lost
   ticket can be invalidated" was a docblock's claim with no code behind it;
   after it, the claim is true, and `admission_tokens_superseded_total`
   (ADR-0059) is the number that says whether the newly-real enforcement is
   catching reissues rather than turning away genuine people. The docblocks
   in `IssueAdmissionToken`, `AdmissionTokenPayload` and `IssueBadge`
   describing the old, incorrect behaviour are removed in the same change
   that makes the claim true.

3. **A mail attachment may now be inline, which narrows a contract
   `AttachmentData` deliberately drew the other way.** Commerce's invoice
   mail is the reason `AttachmentData` only ever named a disk and a path: a
   queued job payload should not carry a PDF's bytes. A calendar file breaks
   that assumption in the opposite direction — it is derived, about half a
   kilobyte, immutable for a given event state, and writing it to a disk to
   satisfy the existing constructor would buy a cache-invalidation problem
   for a file that is cheaper to regenerate than to invalidate.
   `AttachmentData`'s constructor becomes `(string $as, ?string $disk =
   null, ?string $path = null, ?string $content = null, ?string $mime =
   null)`: exactly one of the disk form and the inline form must be set, the
   constructor refuses both and neither, and `TemplatedMail::attachments()`
   builds `Attachment::fromData(...)` for the inline form. Inline content
   over 64 KiB is refused — a ceiling, not a suggestion — so the disk form
   stays the only way to attach anything that could grow into a real payload
   problem, and nobody attaches a rendered PDF by widening the ceiling
   instead of using the door already built for it. This is also why the
   ticket itself is never mailed: it is a download, not an attachment, and
   this ADR does not reopen that.

## Consequences

- A device or a scan that has never seen an admission still accepts any
  token for it (no stored hash), the deliberate offline fail-open the
  design's §5 describes; only online verification is exact from the moment
  of this deploy.
- `IssueAdmissionToken::handle()` no longer exists. Every caller — the badge
  printer, the demo seeder, anything written against the old single method —
  has to be read again and moved to `current()` or `rotate()` explicitly; a
  caller that only ever wanted "don't strand the printed QR" was calling the
  method that, until now, could not have delivered that.
- `AttachmentData`'s two forms are mutually exclusive by construction, not
  by convention: a future caller cannot send both a path and inline bytes
  and have one silently win, and the refusal fires at construction, before
  any mailer is touched.
- `DocumentWatermark` and `Calendar\CalendarFile::forEvent(...)` both live
  in Platform, not in Admission or Event, specifically so the badge (#23)
  and the invoice can adopt the watermark, and any future consumer can call
  the calendar encoder, without importing Admission's or Event's models
  (ADR-0004). Neither module has adopted the watermark yet; the design's
  §12 leaves that as a deliberate follow-up, not an oversight.
- `entrance_delta_entries` gains a nullable `payload` JSON column so a
  `token_superseded` entry can carry the new hash to offline devices; every
  delta-entry kind that existed before this stays payload-less.
```

- [ ] **Final step: Verify and commit**
```bash
git add docs/decisions/0061-the-ticket-is-a-rendered-admission-document.md
git commit -m "Record ADR-0061: the ticket is a rendered Admission document and its token is now stable (#72)"
```

---

### Task 35: Documentation — SPECs, TODOs, a new rules file, the operations runbook, domain language, the module map

**Files:**
- Modify: `app-modules/admission/SPEC.md`
- Modify: `app-modules/admission/TODO.md`
- Modify: `app-modules/event/SPEC.md`
- Modify: `app-modules/event/TODO.md`
- Modify: `app-modules/messaging/SPEC.md`
- Modify: `app-modules/messaging/TODO.md`
- Modify: `app-modules/registration/SPEC.md`
- Modify: `app-modules/registration/TODO.md`
- Create: `.ai/rules/tickets.md`
- Modify: `.ai/rules/index.md`
- Modify: `docs/operations.md`
- Modify: `CLAUDE_extra.md`
- Modify: `docs/architecture/module-map.md`

**Interfaces:**
- Consumes: ADR-0061 (Task 34); every settled interface named at the top of this plan; the existing structure and voice of every file touched.
- Produces: the documentation Task 37's evidence post on #72 points readers at; nothing downstream depends on this task's output programmatically.

- [ ] **Step 1: Admission's `SPEC.md` — the ticket document, the token's new contract, two new read models**
Replace the existing "Ticket tokens" bullet under `## Owns`:
```markdown
- **Ticket tokens** — the scannable credential, its format, and its
  issuance and rotation through `IssueAdmissionToken::current()`/
  `::rotate()` (ADR-0061). The token is stable across repeated issuance:
  `current()` returns the admission's existing nonce, only `rotate()` mints
  a new one, and a token carrying a superseded nonce is refused at the door
  (`token_nonce_hash`, `token_issued_at`,
  `CheckInRejectionReason::NonceSuperseded`).
```
Add three new bullets after the `BadgeIssuance` bullet:
```markdown
- **The ticket document** — one A4 page per Admission, rendered fresh on
  every request and stored nowhere, by
  `Documents\TicketDocumentRenderer::render(Collection $admissions):
  string`. Carries the same KVX1 token a badge or a scan already reads — a
  ticket is a rendering of the access right, not a second credential
  (ADR-0061). The brand mark, the forensic repeat and the TESTSYSTEM band
  come from Platform's `Documents\DocumentWatermark`, the badge's first
  sibling consumer of a service Admission does not own.
- **`Models\Person`** — this module's own holder read model (display name
  only), projected from Identity's `UserRegistered`/`UserProfileUpdated` and
  backfilled by `identity:replay-profiles`, mirroring Registration's
  `Registrant` and Messaging's `Recipient`. Supplies the ticket's and the
  claim flow's holder-name fallback: the linked holder's display name, else
  "Unassigned" — the badge's own fallback, now shared rather than
  re-derived.
- **`Models\EventSummary`** (`admission_event_summaries`) — this module's
  own read model of an event's name, start, end, timezone and venue label,
  projected from Event's `EventCreated`, `EventRescheduled`,
  `VenueAssigned`, `VenueUpdated` and `VenueUnassigned`, and patched (never
  replaced) on `EventDetailsUpdated`. Supplies the ticket's event band;
  Admission may not read Event's models directly (ADR-0004), so this exists
  even though Registration keeps a similarly-shaped copy for its own mail
  path — two read models, not two owners.
- **`admissions.buyer_user_id`** — the registrant who paid for or submitted
  the registration this admission came from, threaded from
  `RegistrationConfirmed`/`RegistrationAmended` and backfilled for
  pre-existing rows by Registration's `registration:announce-ownership`.
  Distinct from `holder_user_id`: a registration-derived admission is born
  with a buyer and no holder, and stays that way until someone claims it.
```
Add three rows to the `## Consumes` table:
```markdown
| `UserRegistered`, `UserProfileUpdated` | Identity | Maintain `Models\Person`, the holder-name read model the ticket and the badge fallback both read (ADR-0061). Backfilled by `identity:replay-profiles` |
| `EventCreated`, `EventRescheduled`, `EventDetailsUpdated`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` | Event | Maintain `Models\EventSummary` (`admission_event_summaries`): times and timezone from the first two, venue label from the venue events, name/slug patched (not replaced) from `EventDetailsUpdated`. Backfilled by `event:replay-details`, which since this feature also re-fires `VenueAssigned` for each event's primary venue |
| `RegistrationOwnershipAnnounced` | Registration | Backfills `admissions.buyer_user_id` for rows that predate the column — the replay-safe counterpart to `RegistrationConfirmed`/`RegistrationAmended`, which already carry `registrantUserId` going forward |
```

- [ ] **Step 2: Admission's `TODO.md` — two backlog items §12 leaves open**
Add to `## Backlog (Phase 2 — release scope, entrance scanning is a definition-of-done item)`:
```markdown
- [ ] Self-service token rotation by the holder (design 2026-09-11 §12,
      LanCore TKT-F-024): `IssueAdmissionToken::rotate()` exists and is
      exercised by the door's own reissue path; only a participant-facing
      "this ticket is compromised, get me a new code" button is missing.
- [ ] Adopt `Platform\Documents\DocumentWatermark` in the badge and the
      invoice (design 2026-09-11 §12): the service is built so
      `BadgeDocumentRenderer` and Commerce's invoice renderer can call it
      later; neither does yet, and each still carries its own ad hoc
      watermarking if any.
```
Add to `## Done`:
```markdown
- [x] Participant ticket document and stable token (#72, ADR-0061):
      `Documents\TicketDocumentRenderer` renders one A4 page per admission
      from the existing KVX1 token — a rendering of the access right, not a
      new credential. `IssueAdmissionToken::current()`/`::rotate()` replace
      `handle()`; a token's nonce is now stored (`token_nonce_hash`,
      `token_issued_at`) and `VerifyAdmissionToken` refuses a superseded one
      (`CheckInRejectionReason::NonceSuperseded`, finally wired after
      existing unreferenced). `Models\Person` and `Models\EventSummary`
      close two read-model gaps the design found while building this
      (2026-09-11 amendments): the ticket's holder-name fallback and its
      event band had nothing to read. SPEC.md's Owns and Consumes updated
      in the same commit.
```

- [ ] **Step 3: Event's `SPEC.md` — the public calendar download**
Add a bullet to `## Owns`:
```markdown
- **The public calendar download** — `GET /events/{event:slug}/calendar.ics`
  (public, unauthenticated, 404 for an event that is not publicly visible,
  matching the landing page). The RFC 5545 encoding itself is Platform's
  `Calendar\CalendarFile::forEvent(...)` (ADR-0061): Event calls it with its
  own scalars — name, description, `starts_at`/`ends_at` converted through
  `startsAtInstant()`/`endsAtInstant()`, `timezone`, the primary venue's
  name and city, and the public event URL — the same scalars-in-bytes-out
  shape every module already uses for `MediaLibrary`. Registration's own
  `registration_event_summaries` carries the same shape for the
  confirmation mail's attachment; Admission keeps a second copy for the
  ticket's event band. Three read models of the same facts, not three
  owners of them — neither consumer may read Event's models directly
  (ADR-0004).
```

- [ ] **Step 4: Event's `TODO.md`**
Add to `## Backlog`:
```markdown
- [ ] A calendar entry per event day or per session, once Agenda exists
      (design 2026-09-11 §12): today's `.ics` is one `VEVENT` for the whole
      event, because an event has no sub-sessions yet.
```
Add to `## Done`:
```markdown
- [x] Public calendar download (#72, ADR-0061): `GET
      /events/{event:slug}/calendar.ics` calls Platform's
      `Calendar\CalendarFile::forEvent(...)` with this module's own scalars.
      `event:replay-details` gained a venue pass — it now also re-fires
      `VenueAssigned` for each event's primary venue — so a backfilled
      calendar file and ticket event band are never silently missing their
      venue on an event created before this deploy.
```

- [ ] **Step 5: Messaging's `SPEC.md` — the inline attachment form**
Add a bullet to `## Owns`, immediately after the existing "Delivery log" bullet:
```markdown
- **Two forms of attachment** (ADR-0061, narrowing the disk-only contract
  this module deliberately drew at Slice 2): `AttachmentData`'s constructor
  accepts either a disk and a path, or inline `content` capped at 64 KiB —
  exactly one of the two, enforced by the constructor, never both and never
  neither. `TemplatedMail::attachments()` builds `Attachment::fromData(...)`
  for the inline form and keeps `fromStorageDisk(...)` for the other. The
  delivery log records `{as, mime}` for an inline attachment with no
  `disk`/`path` — there is nothing to re-fetch — so `Actions\ResendMessage`
  can rebuild a disk-form attachment from the stored row but not an inline
  one. Event's calendar file is the first and, so far, only inline
  consumer; Admission's ticket is deliberately never mailed at all, only
  downloaded, so the size ceiling has not yet had to say no to anything
  bigger.
```

- [ ] **Step 6: Messaging's `TODO.md`**
Add to `## Backlog`:
```markdown
- [ ] `ResendMessage` cannot rebuild an inline attachment (ADR-0061): only
      `{as, mime}` is logged for one, not the bytes. If a digestable or
      resendable message ever needs a persisted inline attachment, the log
      row needs to store the content — the same gap the "Held messages with
      attachments" item above already names for the disk form.
```

- [ ] **Step 7: Registration's `SPEC.md` — the ownership backfill event**
Add a row to `## Public contract (events)`, after `QuotaReleased`:
```markdown
| `RegistrationOwnershipAnnounced` | `registration:announce-ownership` replays it for every existing registration — a narrow backfill event, not a business one. Nothing but a projection may ever listen to it: replaying `RegistrationConfirmed` would re-send every confirmation mail, and this event exists precisely so Admission's `buyer_user_id` backfill does not have to | `registration_id`, `event_id`, `brand_id`, `registrant_user_id`, `announced_at` |
```
Replace the `EventCreated, EventDetailsUpdated, ...` row in `## Consumes` with the wider set `ProjectEventSummary` now listens to:
```markdown
| `EventCreated`, `EventDetailsUpdated`, `EventAnnounced`, `EventPublished`, `EventArchived`, `EventRescheduled`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` | Event | Maintain the event read model (`Models\EventSummary`, projected by `Listeners\ProjectEventSummary`) behind the type editor's event picker and behind `/shop`: name, slug, `visibility`, `public_since` (set by `EventAnnounced`/`EventPublished`, never cleared) and `archived_at`. Together `public_since IS NOT NULL AND visibility = 'public' AND archived_at IS NULL` is `Event::scopeListedPublicly()` — the rule a *listing* surface owes, stricter than `isVisibleOnLanding()` — which is what keeps a draft, an unlisted or an archived event's offers off `/shop` (#240). `EventUnpublished` is deliberately **not** projected: it returns the event to `Announced`, where its landing page still sells. Since ADR-0061, the same row also carries `starts_at`, `ends_at`, `timezone` and `venue_label`, fed by the three new events, for the confirmation mail's calendar attachment; `EventDetailsUpdated` patches name/slug only, never the schedule or venue fields the other events set |
```

- [ ] **Step 8: Registration's `TODO.md`**
Add to `## Done`:
```markdown
- [x] SPEC.md's contract table gained `RegistrationOwnershipAnnounced`
      (`registration:announce-ownership`, ADR-0061) and the wider Consumes
      row for `ProjectEventSummary`: Admission's ticket needs an event's
      time and venue, which neither `registration_event_summaries` nor any
      event Registration already consumed carried before this.
```

- [ ] **Step 9: `.ai/rules/tickets.md` — the traps this feature leaves behind**
```markdown
---
paths:
  - 'app-modules/admission/src/Documents/**'
  - 'app-modules/admission/src/Actions/IssueAdmissionToken.php'
  - 'app-modules/admission/src/Actions/VerifyAdmissionToken.php'
  - 'app-modules/admission/src/Http/Controllers/TicketDownloadController.php'
  - 'app-modules/event/src/Http/Controllers/EventLandingController.php'
  - 'app-modules/platform/src/Documents/**'
  - 'app-modules/platform/src/Calendar/**'
  - 'app-modules/messaging/src/Data/AttachmentData.php'
---

# Tickets and calendar files

## `current()` for a read, `rotate()` for a write — `handle()` no longer exists
Calling `rotate()` where `current()` was meant silently issues a *different*
code and refuses the one already printed the next time it is scanned. Every
read path — a ticket render, a badge print, anything that only needs "the
code that is currently valid" — calls `current()`. Only a deliberate reissue
(a lost ticket, a compromised code) calls `rotate()`, and it always writes a
`token_superseded` delta entry — there is no quiet way to rotate.

## A null `token_nonce_hash` is not a bug, it is the migration path
An admission with no stored hash accepts any otherwise-valid token — every
row minted before ADR-0061 shipped, and every admission an offline device
has never synced. Do not "close" this by refusing a null hash: that would
403 every admission that predates the deploy until it happens to be scanned
once online first. `VerifyAdmissionToken`'s comparison is opt-in per row by
design.

## `buyer_user_id` and `holder_user_id` answer different questions
A registration-derived admission is born with a buyer (whoever paid) and no
holder (nobody named yet); it stays holder-less until an
`AdmissionClaimLink` is claimed. `/my/tickets` — the holder's own list — is
legitimately empty for an unclaimed admission; that is correct, not a
regression. The buyer's own `/my/registrations/{registration}/ticket`
always lists every admission the registration granted regardless of holder.

## Two backfills close two different gaps, and neither replaces a domain event
`registration:announce-ownership` backfills `admissions.buyer_user_id` by
emitting `RegistrationOwnershipAnnounced` — a narrow event only a projection
may ever listen to, never `RegistrationConfirmed` itself, because replaying
that would re-send a confirmation mail for every registration that ever
existed. `event:replay-details` backfills the *event summary* read models
(`admission_event_summaries`, `registration_event_summaries`) and, since
this feature, also re-fires `VenueAssigned` for each event's primary venue
— rerun it after backfilling a venue onto an event created before the two
summaries existed; it does not run itself.

## `AttachmentData`'s two forms are exclusive, and the 64 KiB ceiling is on the Data object
Passing both `path` and `content` (or neither) throws at construction, not
at send time — a bad call site fails the request or job that built the
attachment, before any mailer runs. The ceiling is enforced by
`AttachmentData` itself: a template that renders a body over 64 KiB throws
there too. There is no path around the ceiling except the disk form the
ceiling exists to keep mandatory for anything that size.

## The environment fact is `DeploymentEnvironment::isProduction()`, never `env('APP_ENV')`
`DocumentWatermark::testSystemBanner()` reads it, and `CalendarFile` neither
knows nor cares about environment at all. A ticket rendered on an instance
whose `APP_ENV` was never set shows TESTSYSTEM by design (ADR-0053's
default is `undeclared`, not `production`) — that is the ribbon's own rule
working as intended, not a ticket-rendering bug.

## `CalendarFile` emits UTC instants and no `VTIMEZONE`, on purpose
Do not "improve" this by adding `TZID`: a correct `VTIMEZONE` block needs
full daylight-saving rules the codebase has no source for, and a UTC
instant already renders correctly in every client's own timezone (design
§6.1). `admission_event_summaries` and `registration_event_summaries` are
two separate projections of the same Event facts, not one shared table —
extending one's columns does not extend the other's.

## Tests
A rendered PDF is asserted on its magic bytes and its length, never
byte-compared, following `BadgeDocumentTest`/`InvoiceDocumentTest`. The
calendar file is asserted by parsing it — CRLF endings, folded lines,
escaped text — never by string-matching the raw bytes.

## Observability
No alert exists for anything in this feature (ADR-0059, design §9): a
render or download failure is a plain 500 already covered by
`KonventixHttp5xxRate`/`KonventixErrorsLogged`, and a superseded-nonce
refusal is visible to the person at the door. `admission_tickets_rendered_
total`, `event_calendar_downloads_total` and `admission_tokens_superseded_
total` all live on the `konventix-sale` dashboard's "Tickets and calendars"
row — adding a fourth metric here without a panel fails
`ObservabilityCoverageTest`.
```

- [ ] **Step 10: Row in `.ai/rules/index.md`**
Insert immediately after the observability row:
```markdown
| app-modules/admission/src/Documents/**, app-modules/admission/src/Actions/IssueAdmissionToken.php, app-modules/admission/src/Actions/VerifyAdmissionToken.php, app-modules/admission/src/Http/Controllers/TicketDownloadController.php, app-modules/event/src/Http/Controllers/EventLandingController.php, app-modules/platform/src/Documents/**, app-modules/platform/src/Calendar/**, app-modules/messaging/src/Data/AttachmentData.php | .ai/rules/tickets.md |
```

- [ ] **Step 11: The runbook in `docs/operations.md`**
Insert as a new section after "## Opening seating on an event" and before "## Alerts":
```markdown
## Handing a participant their ticket

A ticket is self-service: the buyer downloads every admission their
registration granted from `/my/registrations/{registration}/ticket`, and
anyone who has claimed an admission downloads their own from `/my/tickets`.
There is no admin route that generates a participant's ticket on an
operator's behalf — that document is deliberately participant-facing only
(ADR-0061). When someone at a desk cannot self-serve (no account, a device
that will not open the mail), the operator equivalent is the **badge**, not
the ticket: `GET /admin/admissions/{admission}/badge` (`BadgeController`,
behind `admission.print-badge`) prints the physical identity document
check-in already reads, from the same KVX1 token. Sending someone to the
door with a printed badge and no ticket is normal; the two documents carry
the same scannable code.

An admission with no linked holder account (`holder_user_id` is null) has no
row in anyone's `/my/tickets` — group and B2B bookings look like this until
a claim link is used. The buyer's own
`/my/registrations/{registration}/ticket` still lists every admission the
registration granted, holder or not, so a buyer can always retrieve every
ticket their registration paid for, claimed or otherwise.

### A superseded-nonce refusal at the door

`CheckInRejectionReason::NonceSuperseded` means the presented code is real
but is not the admission's *current* one — someone is scanning an older
printout or QR after the admission's token was rotated
(`IssueAdmissionToken::rotate()`, run only on a deliberate reissue, never on
an ordinary download). The fix is always the same: the holder needs their
current ticket, downloaded fresh from `/my/tickets` or reprinted as a badge
from the console. This is not paged — the observability task deliberately
adds no alert for it (ADR-0059 point 8), because a refusal is already
visible to the person standing at the door — but
`admission_tokens_superseded_total` on the Sale dashboard's "Tickets and
calendars" row is what an operator watches during a sale: a steady trickle
is reissues behaving as designed, a spike against one event is worth asking
about.

### Backfilling the ticket's read models

Two commands, safe to re-run, neither one specific to a single event:

- `vendor/bin/sail artisan registration:announce-ownership` — re-emits the
  narrow `RegistrationOwnershipAnnounced` event for every existing
  registration, so `admissions.buyer_user_id` is populated for admissions
  issued before this feature shipped. Never re-sends a confirmation mail:
  that is exactly why this is its own event and not a replay of
  `RegistrationConfirmed`.
- `vendor/bin/sail artisan event:replay-details` — re-emits
  `EventRescheduled`, `EventDetailsUpdated` and, since this feature,
  `VenueAssigned` for each event's primary venue, so Admission's
  `admission_event_summaries` and Registration's
  `registration_event_summaries` both pick up a name, a schedule and a
  venue for events created before the two read models existed. Run it again
  after backfilling a venue onto an event that predates this deploy — the
  command does not run itself.
```

- [ ] **Step 12: One line in `CLAUDE_extra.md`'s domain language**
Insert before the closing "See `docs/architecture/module-map.md`..." sentence:
```markdown
A ticket is not a third credential: it is the rendered PDF of an Admission's
existing KVX1 token, and the token itself is now stable rather than
reissued on every read (ADR-0061).
```

- [ ] **Step 13: `docs/architecture/module-map.md` — Platform's ownership grew**
Append to Platform's "Owns" cell, after the existing `Platform\Legal\PublicLegalTexts` clause:
```markdown
, document watermarking (`Documents\DocumentWatermark`, ADR-0061) and the
RFC 5545 calendar encoder (`Calendar\CalendarFile`, ADR-0061) — both take
scalars and return bytes, callable by any module the way they all call
`MediaLibrary`
```

- [ ] **Final step: Verify and commit**
```bash
git add app-modules/admission/SPEC.md app-modules/admission/TODO.md app-modules/event/SPEC.md app-modules/event/TODO.md app-modules/messaging/SPEC.md app-modules/messaging/TODO.md app-modules/registration/SPEC.md app-modules/registration/TODO.md .ai/rules/tickets.md .ai/rules/index.md docs/operations.md CLAUDE_extra.md docs/architecture/module-map.md
git commit -m "Document the ticket and calendar feature: SPEC/TODO updates, a tickets.md rules file, and an operations runbook (#72)"
```

---

### Task 36: Demo data — a clickable ticket and calendar story

**Files:**
- Modify: `database/seeders/Demo/DemoAdmissionSeeder.php`
- Modify: `app/Console/Commands/SeedDemoDataCommand.php`
- Modify: `tests/Feature/SeedDemoDataCommandTest.php`

**Interfaces:**
- Consumes: `Konventix\Admission\Actions\ClaimAdmission::handle(string $linkId, string $claimantUserId, ?string $claimantEmail = null): Admission`; `Konventix\Admission\Actions\IssueAdmissionClaimLink::handle(Admission $admission, ?string $intendedEmail = null): AdmissionClaimLink`; the existing `DemoEventSeeder::assignPrimaryVenue()`, already run for every seeded event (live, concluded and draft alike).
- Produces: a demo dataset reachable through `vendor/bin/sail artisan konventix:seed --default` (the local database is reached only through `vendor/bin/sail`, never host PHP) where a real seeded member's `/my/tickets` is non-empty and a confirmed registration's admissions carry a buyer — the "something clickable" Task 37's evidence can point a human reviewer at for the criteria that need a browser.

- [ ] **Step 1: Confirm the venue requirement needs no change**
`DemoEventSeeder::seedLiveEvent()`/`seedConcludedEvent()`/`seedDraftEvent()`
already call `assignPrimaryVenue()` for every event, live, concluded and
draft alike — so the ticket band and calendar `LOCATION` are already
populated for every seeded event with no seeder change. No file touched for
this part of the requirement; the assertion below (Step 4) is what proves
it rather than a new seeding step.

- [ ] **Step 2: Claim one admission on the spot, so a holder's ticket list is never empty**
`DemoAdmissionSeeder::seedClaimLinks()` today only issues links
(`IssueAdmissionClaimLink`) and never claims one — every seeded admission
therefore has `holder_user_id === null`, and `/my/tickets` is empty for
every demo account. Extend it to claim exactly one, immediately, for a real
member, leaving the rest as open unbound links exactly as before so the
unclaimed-link lifecycle still has a live example too:
```php
use Konventix\Admission\Actions\ClaimAdmission;
use Konventix\Identity\Models\User;

final class DemoAdmissionSeeder
{
    // ...

    public function __construct(
        private readonly IssueAdmissionToken $issueToken,
        private readonly RecordScan $recordScan,
        private readonly RotateEntranceSigningKey $rotateKey,
        private readonly IssueAdmissionClaimLink $issueClaimLink,
        private readonly ClaimAdmission $claimAdmission,
        private readonly MediaLibrary $library,
    ) {}

    /**
     * @param  array<string, MediaItem>  $media
     * @param  Collection<int, User>  $members
     */
    public function handle(Event $event, array $media, Collection $members): void
    {
        $this->seedBadgeTemplate($event, $media);

        $checkpoints = $this->seedCheckpoints($event);

        $this->seedDevices($checkpoints);
        $this->seedCheckIns($event, $checkpoints);
        $this->seedClaimLinks($event, $members);
    }

    // ...

    /**
     * Claim links (ADR-0032): the badge a registrant bought for someone who
     * has no account yet. Only holder-less admissions can be claimed, which
     * is what the query filters on — the action refuses the rest outright.
     *
     * The first is claimed on the spot by a real member: without this, every
     * seeded admission stays holder-less and `/my/tickets` (the holder's own
     * ticket list, #72) is empty for every demo account, which teaches
     * nothing about a screen that exists for exactly this case. It is
     * claimed unbound (`intendedEmail: null`) — `ClaimAdmission` refuses a
     * claimant whose email does not match a bound link's `intended_email`,
     * and a demo member's real email was never the one on the link. The
     * rest stay open, email-bound links, exactly as before.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedClaimLinks(Event $event, Collection $members): void
    {
        $unclaimed = Admission::query()
            ->where('event_id', $event->getKey())
            ->whereNull('holder_user_id')
            ->whereIn('state', [AdmissionState::Issued, AdmissionState::Assigned])
            ->whereNotIn('id', AdmissionClaimLink::query()->select('admission_id'))
            ->limit(2)
            ->get();

        $unclaimed->values()->each(function (Admission $admission, int $index) use ($members): void {
            if ($index === 0 && $members->isNotEmpty()) {
                $holder = $members->first();
                $link = $this->issueClaimLink->handle($admission, intendedEmail: null);
                $this->claimAdmission->handle($link->getKey(), $holder->getKey(), null);

                return;
            }

            $this->issueClaimLink->handle($admission, intendedEmail: fake()->safeEmail());
        });
    }
}
```

- [ ] **Step 3: Thread `$members` through to the admission slice**
`SeedDemoDataCommand::seedEvent()` already has `$members` in scope; only
the call site changes:
```php
$this->inBrand($brand, fn () => $this->admission->handle($event, $media, $members));
```

- [ ] **Step 4: Assert the clickable story in the existing entrance test**
Extend `it('seeds checkpoints, entrance devices and real check-ins', ...)`
in `tests/Feature/SeedDemoDataCommandTest.php` — add the two imports and the
assertions at the end of the test body:
```php
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Registration\Enums\RegistrationStatus;

// ... inside it('seeds checkpoints, entrance devices and real check-ins', ...), appended:

    // The claim-link lifecycle needs one live example claimed, not just
    // issued (#72, ADR-0061) — otherwise the holder's own /my/tickets page
    // is empty for every seeded account and nobody ever sees that screen
    // with data in it.
    $claimed = Admission::withoutBrandScope()
        ->where('event_id', $live->getKey())
        ->whereNotNull('holder_user_id')
        ->first();

    expect($claimed)->not->toBeNull()
        ->and(AdmissionClaimLink::withoutBrandScope()
            ->where('admission_id', $claimed->id)
            ->whereNotNull('claimed_at')
            ->exists())->toBeTrue();

    // A registration-derived admission always carries the registrant who
    // paid for it, holder or not (design 2026-09-11 §13 amendment).
    $confirmedRegistration = Registration::query()
        ->where('event_id', $live->getKey())
        ->where('status', RegistrationStatus::Confirmed)
        ->first();

    expect($confirmedRegistration)->not->toBeNull();

    $itsAdmissions = Admission::withoutBrandScope()
        ->where('registration_id', $confirmedRegistration->id)
        ->get();

    expect($itsAdmissions)->not->toBeEmpty()
        ->and($itsAdmissions->whereNull('buyer_user_id'))->toBeEmpty();
});
```
Note for whoever runs this locally: the seed itself is driven through
`vendor/bin/sail artisan konventix:seed --default` — the database is only
reachable through Sail, never host PHP (`DB_HOST=pgsql` resolves only
in-network).

- [ ] **Final step: Verify and commit**
```bash
flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock vendor/bin/sail artisan test --compact tests/Feature/SeedDemoDataCommandTest.php
git add database/seeders/Demo/DemoAdmissionSeeder.php app/Console/Commands/SeedDemoDataCommand.php tests/Feature/SeedDemoDataCommandTest.php
git commit -m "Claim one demo admission on the spot so a holder's ticket list is never empty (#72)"
```

---

### Task 37: The final gate — backfills, formatting, the full suite, and evidence on #72

**Files:**
- Test: `full-suite.log` under `.superpowers/sdd/2026-09-11-tickets/` (created as this task's own evidence artifact, not committed to git)

**Interfaces:**
- Consumes: every file Tasks 33–36 touched; `registration:announce-ownership`, `event:replay-details`; branch `seating-2026-09-10`, merge request !48, project id 436 on `gitlab.sxcs.de`; GitLab issue #72's acceptance criteria (fetched live, not retyped from memory).
- Produces: a pushed, formatted, fully-tested branch; a green MR pipeline; issue #72 updated with ticked/unticked acceptance criteria and a dated evidence comment. Nothing else in this plan depends on this task's output — it is the last one.

- [ ] **Step 1: Run the two backfills this feature adds or extends**
Safe to re-run; run before the suite so the suite's own assertions and this
evidence agree on the same data:
```bash
vendor/bin/sail artisan registration:announce-ownership
vendor/bin/sail artisan event:replay-details
```

- [ ] **Step 2: The formatter check**
```bash
npx prettier --check resources/ "app-modules/*/resources/**/*.{ts,tsx,vue,js,css,json}"
```
A non-zero exit here means a file the per-edit hook did not touch (a
generated dashboard JSON, a file edited outside this session) is not
prettier-clean; fix it with `npx prettier --write` on the named paths and
re-run before continuing — never push past a failing check.

- [ ] **Step 3: The full suite, once, under the lock, backgrounded**
It runs about twelve minutes; a foreground command in this environment is
killed at ten. Never `composer test` — its Composer process-timeout wrapper
kills a run this long (#193).
```bash
mkdir -p /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets
flock /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/sail.lock \
  vendor/bin/sail artisan test --compact \
  > /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/full-suite.log 2>&1 &
disown
```
Wait for it to finish (it is backgrounded precisely so this does not hit
the ten-minute foreground kill), then read the result:
```bash
wait
tail -n 60 /home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/full-suite.log
```
The log must end `OK` with no `FAILURES`. If it does not: stop here, fix the
failure, and repeat from Step 3 — do not push a red suite.

- [ ] **Step 4: Clean tree, push**
```bash
git status --porcelain
```
Must print nothing. Then:
```bash
git push origin seating-2026-09-10
```

- [ ] **Step 5: Confirm the merge request pipeline is green**
```bash
export GITLAB_HOST=gitlab.sxcs.de
glab api projects/436/merge_requests/48/pipelines | jq -r '.[0] | "\(.id) \(.status)"'
```
Poll until the newest pipeline's `status` is `success` (a `running`/`pending`
status means wait and re-check; `failed`/`canceled` means stop, fix, and
return to Step 3 — do not post evidence against a red pipeline).

- [ ] **Step 6: Update the acceptance criteria on issue #72**
The issue's body field for `glab api` edits is `description`, not `body`.
Fetch the live description rather than retyping it, and always expand a
file's content with `$(cat ...)` — `-f description=@/path` sends the
literal string `@/path`, not the file's contents:
```bash
export GITLAB_HOST=gitlab.sxcs.de
F=/home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/issue-72-description.md

glab api projects/436/issues/72 | jq -r '.description' > "$F"
```
Tick every checkbox this deploy closes — the three "Offen" findings and four
of the five acceptance criteria — by matching each line's stable, ASCII-safe
prefix rather than retyping the (umlaut-bearing) German text by hand:
```bash
sed -i \
  -e 's/^- \[ \] Kein teilnehmerseitiges Ticket/- [x] Kein teilnehmerseitiges Ticket/' \
  -e 's/^- \[ \] Kein Wasserzeichen/- [x] Kein Wasserzeichen/' \
  -e 's/^- \[ \] Kein ICS/- [x] Kein ICS/' \
  -e 's/^- \[ \] Ticket-PDF/- [x] Ticket-PDF/' \
  -e 's/^- \[ \] ICS-Datei/- [x] ICS-Datei/' \
  -e 's/^- \[ \] Button/- [x] Button/' \
  -e 's/^- \[ \] Auf Nicht-Produktion/- [x] Auf Nicht-Produktion/' \
  "$F"
# "Der Ticket-Code ist maschinell lesbar und Grundlage fuer den Scan in #98"
# is deliberately left unticked — see the comment posted in the next step.

glab api projects/436/issues/72 --method PUT -f description="$(cat "$F")"
```

- [ ] **Step 7: Post the dated evidence comment**
```bash
NOTE=/home/mawiguko/git/konventix/konventix-poc/.superpowers/sdd/2026-09-11-tickets/issue-72-note.md

cat > "$NOTE" <<'EOF'
### Verifikation (Agent) — 2026-09-11

Getickt:
- Ticket-PDF: Wasserzeichen (Operator, Brand), Ticket-Eigenschaften,
  Ticket-ID — `TicketDocumentTest` (magic-bytes + Laenge, Wasserzeichen-
  Layer je nach `DeploymentEnvironment::isProduction()` vorhanden/nicht),
  `DocumentWatermarkTest`.
- ICS als Mail-Anhang — `RegistrationConfirmationMailTest` (genau ein
  Anhang, `text/calendar`, Dateiname aus dem Event-Slug), `CalendarFileTest`.
- "Zum Kalender hinzufuegen" auf der Event-Seite — `EventLandingTest`
  (Slot-Contribution auf `event.landing` verlinkt auf `event.calendar`).
- TESTSYSTEM-Wasserzeichen auf Nicht-Produktion — `TicketDocumentTest`
  ("the TESTSYSTEM layer is present off production and absent on
  production, driven through DeploymentEnvironment, never through env()").
- Die drei "Offen"-Punkte aus dem Ausgangsbefund sind durch dieselben Tests
  plus `TicketDownloadController` und die neue My-Tickets-Seite geschlossen (Ticket-,
  PDF- und Kalender-Link jetzt vorhanden).

Nicht getickt: "Der Ticket-Code ist maschinell lesbar und Grundlage fuer den
Scan in #98." Die automatisierten Tests belegen nur, dass der SVG-QR den
richtigen KVX1-String kodiert (`TicketDocumentTest`) und dass derselbe Code
im Scan-Pfad aus #98 akzeptiert wird (`VerifyAdmissionTokenTest`,
`RecordScanTest`). Ob ein Telefon die Kamera-Aufloesung eines gerenderten
oder gedruckten Tickets tatsaechlich decodiert, braucht einen Menschen mit
einem Telefon vor einem echten, gedruckten oder angezeigten Ticket — das
ist hier nicht geprueft und bleibt offen.

Evidenz: vollstaendiger Testlauf (full-suite.log, `vendor/bin/sail artisan
test --compact`, OK), Formatter-Check gruen, MR !48 Pipeline gruen, Branch
seating-2026-09-10 gepusht.
EOF

glab api projects/436/issues/72/notes --method POST -f body="$(cat "$NOTE")"
```

- [ ] **Final step: Verify and commit**
Nothing left to stage — this task pushed already committed work and wrote
only local evidence artifacts under `.superpowers/sdd/2026-09-11-tickets/`,
which is not part of the repository. Confirm the tree is still clean and
the branch has nothing left to push:
```bash
git status --porcelain
git log --oneline origin/seating-2026-09-10..seating-2026-09-10
```
Both must print nothing.
