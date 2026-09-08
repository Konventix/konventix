<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Identity\Models\User;

/**
 * What the privacy pages send the browser (#243 review findings 2-4).
 *
 * Two kinds of value, and they are not interchangeable:
 *
 * An *instant* — when a consent was granted, when a request was opened — goes
 * out as ISO 8601 *with its offset*. `toDateTimeString()` drops it, the
 * browser then parses those digits as the reader's local clock, and the
 * viewer's own zone is applied a second time on top of a value that was
 * already UTC. Before #243 the second shift did not happen and the page was
 * merely wrong by a fixed amount; with a profile zone it became wrong by two
 * different amounts on two devices.
 *
 * A *day* — a statutory deadline, an archive expiry — goes out as `Y-m-d` and
 * is printed by `formatCalendarDate()`, which reads the parts rather than the
 * instant. Read as an instant, `2026-10-08` is UTC midnight and a reader in
 * Honolulu is told the 7th: a day earlier than the law says.
 */
it('sends a granted consent as an instant with its offset', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    Consent::factory()->create([
        'brand_id' => $brand->getKey(),
        'subject_id' => (string) $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/settings/privacy')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('consents.0.grantedAt', fn (string $value): bool => (bool) preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/',
                $value,
            )));
});

it('sends a statutory deadline as the day it is, and the moment it was opened as an instant', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    DataSubjectRequest::factory()->create([
        'filed_on_brand_id' => $brand->getKey(),
        'subject_id' => (string) $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/settings/privacy')
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('requests.0.deadlineAt', fn (string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
            ->where('requests.0.openedAt', fn (string $value): bool => str_contains($value, 'T')));
});
