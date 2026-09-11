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
    ticketSigningKey($brand);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    $bytes = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('renders one document per collection, longer for three admissions than for one', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

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
    ticketSigningKey($brand);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('renders an admission whose event has no summary yet', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

    // No ticketEventSummary() call: the projector has not caught up, or
    // never will for this event.
    $admission = ticketAdmissionFor((string) Str::ulid(), ticketPerson());

    expect(str_starts_with(app(TicketDocumentRenderer::class)->render(collect([$admission])), '%PDF'))->toBeTrue();
});

it('renders a staff admission with no registration at all', function (): void {
    // registration_id is nullable (§4.1) and a staff admission has none —
    // the page must still render.
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

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
    ticketSigningKey($brand);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $admission = ticketAdmissionFor($eventId, ticketPerson());

    app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(Storage::disk('media')->allFiles())->toBe([]);
});

it('reaches the admission token only through IssueAdmissionToken::current(), never rotate()', function (): void {
    // §5: `current()` is the read path every render must use; `rotate()`
    // is a write path only a claim or a reassignment may call. Pinned by
    // reading the renderer's own source rather than mocking
    // `IssueAdmissionToken`: the class is `final` (ADR-0033 signing
    // machinery), and `TicketDocumentRenderer`'s constructor type-hints it
    // strictly, so `$this->mock(IssueAdmissionToken::class, ...)` fails
    // container construction before the closure ever runs — Mockery
    // itself: "Classes marked final can be passed in to Mockery::mock()
    // as instantiated objects to create a partial mock, but only if the
    // mock is not subject to type hinting checks" (it is here). Mirrors
    // `EvaluateAdmissionValidityTest`'s source-content assertion and the
    // design doc §13 pin on `IssueAdmissionsForRegistration`'s wiring.
    $source = file_get_contents(__DIR__.'/../src/Documents/TicketDocumentRenderer.php');

    expect($source)->toContain('->current(')
        ->and($source)->not->toContain('->rotate(');
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
