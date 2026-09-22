<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\ExpireWaitlistOffers;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\PromoteFromWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\QuotaExhausted;
use Konventix\Registration\Events\QuotaReleased;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\WaitlistOfferExpired;
use Konventix\Registration\Events\WaitlistOfferMade;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

/**
 * The order the quota locks are taken in (#233).
 *
 * `->quotas()->lockForUpdate()->get()` used to come back in pivot-scan
 * order, which differs per registration type: two concurrent submissions on
 * two types that share the same two quotas locked them in opposite orders
 * and deadlocked. The order is now declared once, on
 * `RegistrationType::quotas()`, so every lock taker inherits it — the four
 * the issue names plus `SubmitRegistration::guardQuantity()` and
 * `ReduceRegistrationQuantity`, which it does not.
 *
 * These tests assert the order rather than racing two connections, for the
 * reason `CartLockOrderTest` states at length: the suite runs inside
 * RefreshDatabase's transaction, so a second connection sees no fixtures,
 * and a deadlock only ever appears in a timing window a test cannot hold
 * open. What can be asserted exactly is what the fix actually is — that two
 * types hand back the same shared quotas in the same order, and that the
 * locking statement says so.
 */

/**
 * Every statement one closure executed, in order.
 *
 * @return list<string>
 */
function quotaLockQueryLog(Closure $work): array
{
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $work();

    return $statements;
}

/**
 * Every locking read of the `quotas()` relation in a log — a `SELECT` whose
 * own first column is a quota, so the `EXISTS (…)` subquery `PromoteFromWaitlist`
 * uses to find its entry is not mistaken for one.
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function quotaLockStatements(array $statements): array
{
    return array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'select "quotas"')
            && str_contains($sql, 'for update'),
    ));
}

function quotaLockSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

it('hands two types the quotas they share in one order, however the pivot was written', function (): void {
    $this->withBrand();

    $first = Quota::factory()->withCapacity(10)->create();
    $second = Quota::factory()->withCapacity(10)->create();

    $earlyType = RegistrationType::factory()->create();
    $lateType = RegistrationType::factory()->create();

    // Attached in opposite orders on purpose: this is the shape that made
    // two concurrent submissions take the two rows in opposite orders.
    $earlyType->quotas()->attach([$first->id, $second->id]);
    $lateType->quotas()->attach([$second->id, $first->id]);

    $expected = [$first->id, $second->id];
    sort($expected);

    expect($earlyType->quotas()->pluck('quotas.id')->all())->toBe($expected)
        ->and($lateType->quotas()->pluck('quotas.id')->all())->toBe($expected);
});

it('locks the quotas in key order when a registration is submitted and again when it is cancelled', function (): void {
    Event::fake([QuotaExhausted::class, QuotaReleased::class, RegistrationCancelled::class]);
    $this->withBrand();

    $first = Quota::factory()->withCapacity(10)->create();
    $second = Quota::factory()->withCapacity(10)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach([$second->id, $first->id]);

    $registration = null;

    $submitted = quotaLockQueryLog(function () use ($type, &$registration): void {
        $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), quotaLockSubmission());
    });

    $cancelled = quotaLockQueryLog(function () use (&$registration): void {
        app(CancelRegistration::class)->handle($registration, (string) Str::ulid(), 'registrant-withdrew');
    });

    expect(quotaLockStatements($submitted))->not->toBeEmpty()
        ->and(quotaLockStatements($cancelled))->not->toBeEmpty();

    foreach ([...quotaLockStatements($submitted), ...quotaLockStatements($cancelled)] as $sql) {
        expect($sql)->toContain('order by "quotas"."id"');
    }

    // Unchanged behaviour: one unit consumed, then given back.
    expect($first->fresh()->consumed)->toBe(0)
        ->and($second->fresh()->consumed)->toBe(0);
});

it('locks the quotas in key order on both waitlist paths', function (): void {
    // The promotion listener is faked out with the events: each of the two
    // paths is driven here, one at a time, so each one's own statements are
    // the ones logged.
    Event::fake([QuotaExhausted::class, QuotaReleased::class, WaitlistOfferMade::class, WaitlistOfferExpired::class]);
    $brand = $this->withBrand();

    $first = Quota::factory()->withCapacity(1)->create();
    $second = Quota::factory()->withCapacity(1)->create();
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach([$second->id, $first->id]);

    // A lapsed offer holding one unit of each quota, and a queued entry
    // behind it waiting for them.
    $offered = WaitlistEntry::factory()->forType($type)->offered()->create([
        'offer_expires_at' => now()->subMinute(),
    ]);
    $first->consume(1);
    $second->consume(1);

    $queued = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), quotaLockSubmission());

    $expired = quotaLockQueryLog(fn () => app(ExpireWaitlistOffers::class)->handle());

    $promoted = quotaLockQueryLog(function () use ($first, $brand): void {
        app(PromoteFromWaitlist::class)->handle($first->id, $brand->getKey());
    });

    expect(quotaLockStatements($expired))->not->toBeEmpty()
        ->and(quotaLockStatements($promoted))->not->toBeEmpty()
        ->and($offered->refresh()->state)->toBe(WaitlistState::Expired)
        // Unchanged behaviour: the released units went into the next
        // entry's hold.
        ->and($queued->refresh()->state)->toBe(WaitlistState::Offered)
        ->and($first->refresh()->remaining())->toBe(0)
        ->and($second->refresh()->remaining())->toBe(0);

    foreach ([...quotaLockStatements($expired), ...quotaLockStatements($promoted)] as $sql) {
        expect($sql)->toContain('order by "quotas"."id"');
    }
});
