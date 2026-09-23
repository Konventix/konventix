<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Konventix\Admission\Documents\BadgeDocumentRenderer;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Identity\Models\User;

/**
 * ADR-0068 §6: an invoice freezes its locale, a ticket does not. A ticket
 * and a badge are re-rendered on demand from current state (ADR-0061), so
 * each page speaks its holder's *current* effective locale — whoever
 * downloads or prints it, and whatever locale the worker is in.
 *
 * A rendered PDF is never string-matched (`.ai/rules/tickets.md`), so what
 * each page was composed in is read through a view composer.
 */
it('renders each ticket page in its own holder’s locale', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);

    $german = User::factory()->create(['locale' => 'de']);
    $english = User::factory()->create(['locale' => 'en']);
    ticketPerson(['id' => $german->id, 'email' => $german->email]);
    ticketPerson(['id' => $english->id, 'email' => $english->email]);

    $admissions = collect([
        ticketAdmissionFor($eventId, overrides: ['holder_user_id' => $german->id, 'state' => AdmissionState::Assigned]),
        ticketAdmissionFor($eventId, overrides: ['holder_user_id' => $english->id, 'state' => AdmissionState::Assigned]),
    ]);

    $pages = [];
    View::composer('admission::tickets.page', function () use (&$pages): void {
        $pages[] = [app()->getLocale(), __('Door code')];
    });

    app()->setLocale('en');

    $pdf = app(TicketDocumentRenderer::class)->render($admissions);

    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and($pages)->toBe([['de', 'Einlasscode'], ['en', 'Door code']])
        ->and(app()->getLocale())->toBe('en');
});

it('renders an unheld ticket in the buyer’s locale', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $buyer = User::factory()->create(['locale' => 'de']);

    $seen = null;
    View::composer('admission::tickets.page', function () use (&$seen): void {
        $seen = __('Unassigned');
    });

    app(TicketDocumentRenderer::class)->render(collect([
        ticketAdmissionFor($eventId, overrides: ['buyer_user_id' => $buyer->id]),
    ]));

    expect($seen)->toBe('Nicht zugewiesen');
});

it('renders a badge in its holder’s locale', function (): void {
    $this->withBrand();
    $holder = User::factory()->create(['locale' => 'de']);
    $admission = ticketAdmissionFor((string) Str::ulid(), overrides: ['holder_user_id' => $holder->id]);

    $seen = null;
    View::composer('admission::badges.document', function () use (&$seen): void {
        $seen = app()->getLocale();
    });

    app()->setLocale('en');

    app(BadgeDocumentRenderer::class)->render($admission, null, null, null);

    expect($seen)->toBe('de')->and(app()->getLocale())->toBe('en');
});
