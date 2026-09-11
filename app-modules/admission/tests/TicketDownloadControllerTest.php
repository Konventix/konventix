<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;
use Symfony\Component\Finder\Finder;

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

it('queries the buyer\'s admissions ordered by creation, ascending, before rendering', function (): void {
    // `TicketDocumentRenderer` is `final` and `buyer()` type-hints it
    // strictly, so `$this->mock(TicketDocumentRenderer::class, ...)` fails
    // container construction before the closure ever runs — the identical
    // Mockery limitation `TicketDocumentTest.php` already documents for
    // `IssueAdmissionToken` ("Classes marked final can be passed in to
    // Mockery::mock() ... but only if the mock is not subject to type
    // hinting checks"). Pinned instead by reading the controller's own
    // source for the ordering it applies before handing the collection to
    // the renderer, mirroring that same test's source-content assertion.
    $source = (string) file_get_contents(dirname(__DIR__).'/src/Http/Controllers/TicketDownloadController.php');

    preg_match('/function buyer\(.*?\n    \}/s', $source, $match);

    expect($match)->not->toBeEmpty('TicketDownloadController::buyer() not found.');

    $body = $match[0];

    expect($body)->toContain("orderBy('created_at')")
        ->and($body)->not->toContain('orderByDesc')
        ->and($body)->toContain('render($admissions)');
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

/**
 * design §13, 2026-09-11 amendment "the unconfirmed-registration refusal in
 * §4.1 is structural, not a check": `buyer()` cannot ask Registration
 * whether a registration is confirmed without reading its table (ADR-0004),
 * so the refusal instead rests on an invariant no controller enforces
 * directly — no admission row can exist for an unconfirmed registration,
 * because `IssueAdmissionsForRegistration` is only ever reached from
 * `RegistrationConfirmed` and `RegistrationAmended`. This pins that wiring,
 * following `RegistrationCommerceImportBoundaryTest.php`'s own
 * static-analysis style, so a future issuance path added elsewhere fails
 * here instead of quietly handing out tickets for an unconfirmed
 * registration.
 */
function admissionListenersInjecting(string $actionClass): array
{
    $finder = Finder::create()
        ->files()
        ->in(dirname(__DIR__).'/src/Listeners')
        ->name('*.php');

    $matches = [];

    foreach ($finder as $file) {
        if (str_contains($file->getContents(), $actionClass.' $action')) {
            $matches[] = $file->getFilenameWithoutExtension();
        }
    }

    sort($matches);

    return $matches;
}

it('wires IssueAdmissionsForRegistration to RegistrationConfirmed and RegistrationAmended only', function (): void {
    expect(admissionListenersInjecting('IssueAdmissionsForRegistration'))->toBe([
        'IssueAdmissionsOnRegistrationAmended',
        'IssueAdmissionsOnRegistrationConfirmed',
    ]);

    $providerSource = (string) file_get_contents(dirname(__DIR__).'/src/Providers/AdmissionServiceProvider.php');

    preg_match_all(
        '/Event::listen\(\s*(\w+)::class\s*,\s*(\w+)::class\s*,?\s*\)/s',
        $providerSource,
        $registrations,
        PREG_SET_ORDER,
    );

    $wiredTo = [];

    foreach ($registrations as $registration) {
        if (in_array($registration[2], [
            'IssueAdmissionsOnRegistrationConfirmed',
            'IssueAdmissionsOnRegistrationAmended',
        ], true)) {
            $wiredTo[$registration[2]] = $registration[1];
        }
    }

    ksort($wiredTo);

    expect($wiredTo)->toBe([
        'IssueAdmissionsOnRegistrationAmended' => 'RegistrationAmended',
        'IssueAdmissionsOnRegistrationConfirmed' => 'RegistrationConfirmed',
    ]);
});
