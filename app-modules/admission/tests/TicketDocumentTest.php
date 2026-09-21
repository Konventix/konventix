<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Platform\Documents\DocumentWatermark;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\MediaItem;
use Mockery\MockInterface;

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

it('prints the event band time as the stored wall clock, never shifted by its timezone', function (): void {
    // Regression pin: `starts_at`/`ends_at` are wall-clock with no offset
    // (ADR-0027) — handing `OperatorDateTimeFormat::dateTime()` a zone
    // converts instead of merely labelling it, so a 10:00 CEST event
    // printed as 12:00. Europe/Berlin is UTC+2 in October, so this
    // fixture's default 10:00-18:00 window catches the shift a UTC
    // fixture would hide.
    $brand = $this->withBrand(ticketOperatorBrand());
    $event = ticketEventSummary($brand, (string) Str::ulid());

    $when = (new ReflectionMethod(TicketDocumentRenderer::class, 'eventWhenFor'))
        ->invoke(app(TicketDocumentRenderer::class), $event);

    expect($when)->toBe('16.10.2026 10:00 – 18.10.2026 18:00 (Europe/Berlin)');
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

it('hands the watermark service the forensic lines composed from the page, and the brand logo as its seed image', function (): void {
    // §4.3: holder name, event name, venue, operator legal name, role and
    // the admission id, in that order, composed here as plain strings —
    // "the service knows nothing about admissions".
    $brand = $this->withBrand(ticketOperatorBrand(['legal_name' => 'Sund-Xplosion e.V.']));
    ticketSigningKey($brand);

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

it('asks the watermark service for the TESTSYSTEM band on every page', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);

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

it('paints the TESTSYSTEM layer before the content, so the opaque QR box covers it too', function (): void {
    // Regression pin (§4.3): "a clear rectangle kept around the QR" is a
    // property of every watermark layer, not only the forensic repeat.
    // dompdf paints absolutely positioned, z-index:auto siblings in
    // document order, the same as a browser — `.qr-box` is opaque and
    // lives inside `.content`, so `.testsystem-layer` must be emitted
    // before `.content` in the markup or the band paints on top of the
    // QR instead of being covered by it. Reads the raw Blade output
    // directly (no PDF, no dompdf, no database) because this is a
    // property of the view's markup order, not of anything a renderer or
    // dompdf could change.
    $html = view('admission::tickets.document', ['pages' => [ticketPageFixture()]])->render();

    $watermarkPosition = mb_strpos($html, 'class="watermark-layer"');
    $testsystemPosition = mb_strpos($html, 'class="testsystem-layer"');
    $contentPosition = mb_strpos($html, 'class="content"');

    expect($watermarkPosition)->not->toBeFalse()
        ->and($testsystemPosition)->not->toBeFalse()
        ->and($contentPosition)->not->toBeFalse()
        ->and($watermarkPosition)->toBeLessThan($testsystemPosition)
        ->and($testsystemPosition)->toBeLessThan($contentPosition);
});

// The seat (#292 §5) and the banner (§5a): both printed when this module's
// read models carry them, both absent without costing the render, and the
// banner painted after the watermark layers and before the QR box.
it('prints the seat and the banner when they exist, and renders without them when they do not', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);
    Storage::fake('media');

    $eventId = (string) Str::ulid();
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id]);
    Storage::disk('media')->put($banner->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    ticketEventSummary($brand, $eventId, ['banner_media_id' => $banner->id]);
    $admission = ticketAdmissionFor($eventId, ticketPerson());
    $seat = new AdmissionSeat(['admission_id' => $admission->id, 'booking_id' => (string) Str::ulid(), 'place_ref' => 'A-12', 'place_label' => 'A-12']);
    $seat->forceFill(['brand_id' => $brand->id])->save();

    $with = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    expect(str_starts_with($with, '%PDF'))->toBeTrue()
        ->and(strlen($with))->toBeGreaterThan(1000);

    // The picture is gone: the media row deleted, the summary still naming it.
    $banner->delete();
    $seat->delete();

    $without = app(TicketDocumentRenderer::class)->render(collect([$admission->fresh()]));

    expect(str_starts_with($without, '%PDF'))->toBeTrue()
        ->and(strlen($without))->toBeLessThan(strlen($with));
});

it('paints the banner after the watermark layers and before the QR box, and names the seat', function (): void {
    $html = view('admission::tickets.document', ['pages' => [ticketPageFixture([
        'banner' => 'data:image/png;base64,iVBORw0KGgo=',
        'seatLabel' => 'A-12',
    ])]])->render();

    $testsystemPosition = mb_strpos($html, 'class="testsystem-layer"');
    $bannerPosition = mb_strpos($html, 'class="banner"');
    $qrPosition = mb_strpos($html, 'class="qr-box"');

    expect($bannerPosition)->not->toBeFalse()
        ->and($testsystemPosition)->toBeLessThan($bannerPosition)
        ->and($bannerPosition)->toBeLessThan($qrPosition)
        ->and($html)->toContain('Seat: A-12');
});

it('paints the banner as a cover background, never as an image dompdf would stretch', function (): void {
    // Regression pin: dompdf implements no `object-fit`, so an `<img>` in
    // the 100% x 22mm band was stretched into it. `background-size: cover`
    // is what dompdf does implement (`Css\Style::compute_background_size`),
    // so the band paints the picture as its own background. Reads the raw
    // Blade output for the same reason the layer-order pin does: this is a
    // property of the markup, not of anything the renderer contributes.
    $html = view('admission::tickets.document', ['pages' => [ticketPageFixture([
        'banner' => 'data:image/webp;base64,UklGRg==',
    ])]])->render();

    preg_match('/<div class="banner"[^>]*>(.*?)<\/div>/s', $html, $band);

    expect($band)->not->toBeEmpty()
        ->and($band[0])->toContain("background-image: url('data:image/webp;base64,UklGRg==')")
        ->and($band[1])->not->toContain('<img')
        ->and($html)->toContain('background-size: cover')
        ->and($html)->not->toContain('object-fit');
});

/**
 * A picture PNG cannot squash: per-pixel noise, so the inlined bytes scale
 * with the pixel count and the band-shaped `ticket_banner` strip (1240 x 130)
 * is measurably smaller than the 1600 x 400 original it was cut from.
 */
function ticketNoisyPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    mt_srand(72);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }

    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

it('inlines the ticket_banner variant when the picture has one, and the whole original when it does not', function (): void {
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);
    Storage::fake('media');

    $eventId = (string) Str::ulid();
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id]);
    Storage::disk('media')->put($banner->path, ticketNoisyPng(1600, 400));
    // The variants Platform cuts at upload (`StoreMediaUpload`), produced
    // by the real generator so this covers the crop as well as the choice.
    $banner->update(['variants' => app(ThumbnailGenerator::class)->generate(Storage::disk('media'), $banner->path, 'image/png')]);
    ticketEventSummary($brand, $eventId, ['banner_media_id' => $banner->id]);
    $admission = ticketAdmissionFor($eventId, ticketPerson());
    $library = app(MediaLibrary::class);

    // What the renderer inlines, measured where it can be: dompdf
    // rasterises the band itself at `TicketDocumentRenderer::DPI`, so the
    // PDF's size says nothing about the bytes that went in. The strip is
    // a fraction of the original — fewer pixels, and lossy.
    $strip = $library->dataUriForPreferring($banner->id, ['ticket_banner', 'thumb']);
    $original = $library->dataUriFor($banner->id);

    expect($banner->variants)->toHaveKey('ticket_banner')
        ->and($strip)->toBe($library->dataUriFor($banner->id, 'ticket_banner'))
        ->and(strlen($strip))->toBeLessThan(intdiv(strlen($original), 4));

    $withVariant = app(TicketDocumentRenderer::class)->render(collect([$admission]));

    // A row from before the variant existed: the same original, no strip
    // to prefer, and the render must still come out.
    $banner->update(['variants' => []]);

    expect($library->dataUriForPreferring($banner->id, ['ticket_banner', 'thumb']))->toBe($original);

    $withOriginal = app(TicketDocumentRenderer::class)->render(collect([$admission->fresh()]));

    expect(str_starts_with($withVariant, '%PDF'))->toBeTrue()
        ->and(str_starts_with($withOriginal, '%PDF'))->toBeTrue();
});

it('resolves the brand logo and the banner once each for a multi-page file, not once per page', function (): void {
    // Each resolution is a row lookup plus a storage read — three round
    // trips on S3 — and every page of a buyer's file shows the same mark
    // and, for one event, the same banner, so the renderer hoists both.
    // `MediaLibrary` is not final, so unlike `IssueAdmissionToken` it can
    // be mocked here; Mockery's `once()` is what fails this when a page
    // asks again, and `with()` pins the preference order: the generated
    // variant first, the original only for a row without one.
    $brand = $this->withBrand(ticketOperatorBrand());
    ticketSigningKey($brand);
    $logo = MediaItem::factory()->create(['brand_id' => $brand->id]);
    $brand->update(['logo_media_id' => $logo->id]);
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id]);

    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId, ['banner_media_id' => $banner->id]);
    $admissions = Admission::factory()->count(3)->create(['event_id' => $eventId]);

    $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    $this->mock(MediaLibrary::class, function (MockInterface $mock) use ($logo, $banner, $pixel): void {
        // `thumb` for the logo: 480px is plenty for a 38mm masthead and a
        // 6%-opacity brand mark.
        $mock->shouldReceive('dataUriForPreferring')
            ->once()
            ->with($logo->id, ['thumb'])
            ->andReturn($pixel);
        // The band-shaped strip for the banner, the thumb as second best.
        $mock->shouldReceive('dataUriForPreferring')
            ->once()
            ->with($banner->id, ['ticket_banner', 'thumb'])
            ->andReturn($pixel);
    });

    $bytes = app(TicketDocumentRenderer::class)->render($admissions);

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(preg_match_all('/\/Type\s*\/Page(?!s)/', $bytes))->toBe(3);
});
