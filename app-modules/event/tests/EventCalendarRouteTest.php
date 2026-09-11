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
