<?php

declare(strict_types=1);

use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;

/**
 * `effective_at` is an instant, not a calendar day (B5, #203).
 *
 * The public page used to receive it as `toDateString()`, so an operator in
 * Berlin who dated a version 2026-09-08T00:30 — stored as 2026-09-07T22:30Z
 * — published a text whose own page said it had been in effect since
 * 7 September. The date was right in the database and wrong on screen, in
 * the one place a reader could check it.
 *
 * The server therefore sends the offset and lets the browser resolve the
 * zone, exactly as the registration timestamps do (#62): the reader's zone
 * is the browser's to know, and `formatDateTime()` is where it is applied
 * (`resources/js/lib/datetime.ts`, covered by `datetime.test.ts`).
 */
it('sends the public policy page an instant with its offset, not a bare calendar day', function (): void {
    $this->withBrand();

    $policy = Policy::factory()->terms()->create();
    PolicyVersion::factory()->for($policy)->published()->create([
        // 00:30 on 8 September in Berlin (UTC+2 in September) is 22:30 on
        // 7 September in UTC — a day earlier, which is what the page used
        // to print.
        'effective_at' => '2026-09-07 22:30:00',
    ]);

    $this->get('/policies/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Show')
            ->where('policy.effectiveAt', '2026-09-07T22:30:00+00:00'));
});

it('sends the consent gate the same instant for every outstanding version', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $policy = Policy::factory()->terms()->create();
    PolicyVersion::factory()->for($policy)->published()->create([
        'requires_consent' => true,
        'effective_at' => '2026-09-07 22:30:00',
    ]);

    $this->actingAs($user)
        ->get('http://localhost/policies-required')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('policies/Required')
            ->where('versions.0.effectiveAt', '2026-09-07T22:30:00+00:00'));
});
