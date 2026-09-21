<?php

declare(strict_types=1);

use Konventix\Support\Actions\PurgeSupportRequests;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * Design §10.7, D-SUP-11: a closed report and its thread are deleted once
 * the window has passed, and `closed_at` is the only clock.
 */
it('deletes a closed report past the window and its whole thread', function (): void {
    $old = SupportRequest::factory()->closedAt(now()->subDays(181))->create();
    SupportRequestMessage::factory()->for($old, 'request')->count(2)->create();

    $recent = SupportRequest::factory()->closedAt(now()->subDays(179))->create();

    expect(app(PurgeSupportRequests::class)->handle())->toBe(1)
        ->and(SupportRequest::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(SupportRequest::query()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(SupportRequestMessage::query()->count())->toBe(0);
});

/**
 * An old *open* report is a triage failure, not a retention one — and
 * `support_requests_unseen_oldest_age_seconds` is what says so. Purging it
 * would delete the evidence of the very problem the gauge is reporting.
 */
it('never purges an open report however old it is', function (): void {
    SupportRequest::factory()
        ->inState(SupportRequestState::InProgress)
        ->create(['created_at' => now()->subYears(2)]);

    expect(app(PurgeSupportRequests::class)->handle())->toBe(0)
        ->and(SupportRequest::query()->count())->toBe(1);
});

it('reads the window from config/support.php', function (): void {
    config()->set('support.retention.days', 7);

    SupportRequest::factory()->closedAt(now()->subDays(8))->create();
    SupportRequest::factory()->closedAt(now()->subDays(6))->create();

    expect(app(PurgeSupportRequests::class)->handle())->toBe(1);
});

/**
 * The window is operator-editable config, so a nonsensical value is
 * refused rather than obeyed — zero would delete every report the moment
 * it closed.
 */
it('refuses a window of zero rather than deleting everything', function (): void {
    config()->set('support.retention.days', 0);

    SupportRequest::factory()->closedAt(now()->subDays(400))->create();

    expect(app(PurgeSupportRequests::class)->handle())->toBe(0)
        ->and(SupportRequest::query()->count())->toBe(1);
});

it('purges past one chunk in a single run', function (): void {
    SupportRequest::factory()->count(PurgeSupportRequests::CHUNK + 3)->closedAt(now()->subDays(200))->create();

    expect(app(PurgeSupportRequests::class)->handle())->toBe(PurgeSupportRequests::CHUNK + 3)
        ->and(SupportRequest::query()->count())->toBe(0);
});

it('is reachable as the scheduled command', function (): void {
    SupportRequest::factory()->closedAt(now()->subDays(200))->create();

    $this->artisan('support:purge-requests')
        ->expectsOutputToContain('1 closed request(s) deleted after 180 day(s)')
        ->assertSuccessful();

    expect(SupportRequest::query()->count())->toBe(0);
});

it('takes an explicit window from the command line', function (): void {
    SupportRequest::factory()->closedAt(now()->subDays(10))->create();

    $this->artisan('support:purge-requests', ['--days' => 5])->assertSuccessful();

    expect(SupportRequest::query()->count())->toBe(0);
});
