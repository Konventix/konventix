<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Models\Admission;
use Symfony\Component\Finder\Finder;

/**
 * `admission:announce-ownership` (#292) re-emits AdmissionOwnershipAnnounced
 * for every admission so Resources can backfill `resource_admissions` for
 * rows written before that read model existed. Deliberately not a replay of
 * `AdmissionIssued`: that event also drives the metrics recorders, and
 * re-emitting it would count every admission ever issued a second time.
 */
it('emits one AdmissionOwnershipAnnounced per admission carrying holder, buyer and state', function (): void {
    $brand = $this->withBrand();
    $buyer = (string) Str::ulid();
    $holder = (string) Str::ulid();
    $named = Admission::factory()->forRegistration((string) Str::ulid())->assigned($holder)->create(['buyer_user_id' => $buyer]);
    $bare = Admission::factory()->create();

    Event::fake([AdmissionOwnershipAnnounced::class]);

    $this->artisan('admission:announce-ownership')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Event::assertDispatchedTimes(AdmissionOwnershipAnnounced::class, 2);
    Event::assertDispatched(
        AdmissionOwnershipAnnounced::class,
        fn (AdmissionOwnershipAnnounced $e): bool => $e->admissionId === $named->id
            && $e->registrationId === $named->registration_id
            && $e->eventId === $named->event_id
            && $e->brandId === $brand->id
            && $e->holderUserId === $holder
            && $e->buyerUserId === $buyer
            && $e->state === 'assigned',
    );
    Event::assertDispatched(
        AdmissionOwnershipAnnounced::class,
        fn (AdmissionOwnershipAnnounced $e): bool => $e->admissionId === $bare->id
            && $e->holderUserId === null
            && $e->buyerUserId === null
            && $e->state === 'issued',
    );
});

it('walks the table in chunks', function (): void {
    $this->withBrand();
    Admission::factory()->count(3)->create();

    Event::fake([AdmissionOwnershipAnnounced::class]);

    $this->artisan('admission:announce-ownership', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(AdmissionOwnershipAnnounced::class, 3);
});

// The rule the event's docblock states: only a projection may listen. A
// mailer, a metric or a delta-feed writer reacting to a backfill would run
// once per admission ever issued, every time the command runs. Pinned
// statically over every module's provider, the way
// `TicketDownloadControllerTest` pins the issuance wiring.
it('is heard only by projections', function (): void {
    $finder = Finder::create()
        ->files()
        ->in(dirname(__DIR__, 2))
        ->path('/src\/Providers\//')
        ->name('*ServiceProvider.php');

    $offenders = [];

    foreach ($finder as $file) {
        preg_match_all(
            '/Event::listen\(\s*AdmissionOwnershipAnnounced::class\s*,\s*\[?\s*(\w+)::class/s',
            $file->getContents(),
            $matches,
        );

        foreach ($matches[1] as $listener) {
            if (! str_starts_with($listener, 'Project')) {
                $offenders[] = $file->getFilenameWithoutExtension().' → '.$listener;
            }
        }
    }

    expect($offenders)->toBe([]);
});
