<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Support\TicketReference;
use Konventix\Identity\Models\User;

/**
 * The short ticket reference (attendee-list design §3, #329): the last eight
 * characters of the admission ULID, upper-cased, grouped `XXXX-XXXX`. One
 * class now defines it for the PDF, the holder's page and the attendee
 * list's search.
 */
it('takes the last eight characters of the ULID, upper-cased and grouped four and four', function (): void {
    // By hand: '01jabcdefghjkmnpqrstvwxyz9' has 26 characters; characters
    // 19–26 are 's','t','v','w','x','y','z','9' = 'stvwxyz9'. Upper-cased
    // that is 'STVWXYZ9', and split 4+4 with a hyphen, 'STVW-XYZ9'.
    expect(TicketReference::of('01jabcdefghjkmnpqrstvwxyz9'))->toBe('STVW-XYZ9');
});

it('turns a typed reference into a fragment without the hyphen or the whitespace', function (): void {
    // By hand: ' stvw-xyz9 ' minus the two spaces and the hyphen is
    // 'stvwxyz9'. Case is left alone: the search is ILIKE.
    expect(TicketReference::searchFragment(' stvw-xyz9 '))->toBe('stvwxyz9')
        // 'AB12CD34' has nothing to strip, so it comes back unchanged.
        ->and(TicketReference::searchFragment('AB12CD34'))->toBe('AB12CD34');
});

it('prints the same reference on the holder page and in the PDF for one admission', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    $holder = User::factory()->create();
    ticketPerson(['id' => $holder->id, 'email' => $holder->email]);
    $admission = Admission::factory()->create([
        'id' => '01jabcdefghjkmnpqrstvwxyz9',
        'event_id' => $eventId,
        'holder_user_id' => $holder->id,
        'state' => AdmissionState::Assigned,
    ]);

    // The PDF's text cannot be read back (TicketDocumentTest's docblock),
    // so read what the renderer hands the Blade view instead.
    $printed = [];
    View::composer('admission::tickets.document', function ($view) use (&$printed): void {
        foreach ($view->getData()['pages'] as $page) {
            $printed[] = $page['doorCode'];
        }
    });

    app(TicketDocumentRenderer::class)->render(collect([$admission]));

    // Derived as in the first test: characters 19–26 of the id give 'STVW-XYZ9'.
    expect($printed)->toBe(['STVW-XYZ9']);

    $this->actingAs($holder)
        ->get('/my/tickets/'.$admission->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admission/tickets/Show')
            ->where('doorCode', 'STVW-XYZ9'));
});
