<?php

declare(strict_types=1);

use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Competition\Models\Entrant;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceType;
use Konventix\Staffing\Models\Team;
use Laravel\Pennant\Feature;

/*
 * The demo seed's heaviest slices: the two archetypes built from one code
 * path, the settlement chain behind them, and the entrance data. Split out of
 * one file that ran 92s and set the floor for the whole parallel suite (#262)
 * — paratest hands out whole files, so a file is only as splittable as the
 * tests in it. The lighter assertions live in SeedDemoDataSlicesTest, the
 * 1,200-member rush fixture in SeedDemoRushCommandTest, and the helpers all
 * three call in tests/Helpers.php.
 */
// The seed writes real files through StoreMediaUpload; without a fake disk
// every run of this suite litters the configured media storage. Faked by the
// deployment's configured name rather than 'media' literally: a checkout that
// points MEDIA_DISK at S3 must still be tested against the disk uploads
// actually land on.
beforeEach(function (): void {
    Storage::fake(mediaDiskNameForTest());
});

/**
 * The definition of done names two events, and they are not the same shape:
 * SXLan is a LAN party, Xpertise a trade fair. Both must come out of the same
 * seed with no code path of their own — everything that differs between them
 * is configuration a brand's own operator could enter by hand
 * (`EventArchetype`).
 *
 * This is the test that would fail if an archetype ever grew a branch inside
 * a module instead of a value in the enum.
 */
it('seeds a LAN party and a trade fair from the same code', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 12, brands: 2);

    $lan = seededDemoBrandForTest();
    $fair = seededDemoFairBrandForTest();

    expect($lan)->not->toBeNull()
        ->and($fair)->not->toBeNull();

    // Module activation is the one structural difference (ADR-0003): a trade
    // fair runs no tournaments, so the gate is closed for it — which is also
    // the only way a seed demonstrates the gate at all.
    $gate = app(ModuleGate::class);
    expect(Feature::for($lan)->active($gate->featureName('competition')))->toBeTrue()
        ->and(Feature::for($fair)->active($gate->featureName('competition')))->toBeFalse()
        // Everything else they agree on.
        ->and(Feature::for($fair)->active($gate->featureName('resources')))->toBeTrue()
        ->and(Feature::for($fair)->active($gate->featureName('staffing')))->toBeTrue();

    // The shop is a second on/off demonstration (ADR-0047): a per-brand
    // feature with no module behind it, switched on only for the LAN
    // archetype's players — a trade fair's catalogue is the whole story for
    // it here, so it carries no merch.
    expect(Feature::for($lan)->active('commerce:shop'))->toBeTrue()
        ->and(Feature::for($fair)->active('commerce:shop'))->toBeFalse()
        ->and(Product::withoutBrandScope()->where('brand_id', $lan->getKey())->count())->toBe(2)
        ->and(Product::withoutBrandScope()->where('brand_id', $fair->getKey())->count())->toBe(0);

    // Nothing was seeded behind the closed gate.
    expect(Entrant::withoutBrandScope()->where('brand_id', $fair->getKey())->count())->toBe(0)
        ->and(Entrant::withoutBrandScope()->where('brand_id', $lan->getKey())->count())->toBeGreaterThan(0);

    $lanEvent = seededDemoEventsForTest($lan)->firstWhere('state', EventLifecycleState::Running);
    $fairEvent = seededDemoEventsForTest($fair)->firstWhere('state', EventLifecycleState::Running);

    // The catalogue: players and sponsors against visitors and exhibitors.
    $lanTypes = RegistrationType::withoutBrandScope()->where('event_id', $lanEvent->getKey())->pluck('name');
    $fairTypes = RegistrationType::withoutBrandScope()->where('event_id', $fairEvent->getKey())->pluck('name');

    expect($lanTypes)->toContain('Player', 'Sponsor')
        ->and($lanTypes)->not->toContain('Visitor', 'Exhibitor')
        ->and($fairTypes)->toContain('Visitor', 'Exhibitor')
        ->and($fairTypes)->not->toContain('Player', 'Sponsor')
        // The shared half of the catalogue is shared.
        ->and($lanTypes)->toContain('Crew', 'Late bird', 'Supporter')
        ->and($fairTypes)->toContain('Crew', 'Late bird', 'Supporter');

    // The floor: a seat grid against booth rows and meeting rooms. Both carry
    // a booth, because that is what the trade type's resource claim binds to.
    $lanResources = ResourceType::withoutBrandScope()->where('event_id', $lanEvent->getKey())->pluck('name');
    $fairResources = ResourceType::withoutBrandScope()->where('event_id', $fairEvent->getKey())->pluck('name');

    expect($lanResources)->toContain('Seat', 'Booth', 'Locker')
        ->and($fairResources)->toContain('Booth', 'Meeting room', 'Locker')
        ->and($fairResources)->not->toContain('Seat');

    // A claim was fulfilled on both floors, whatever the noun.
    expect(ResourceBooking::withoutBrandScope()
        ->where('brand_id', $fair->getKey())
        ->whereNotNull('registration_id')
        ->count())->toBeGreaterThan(0);

    // Venues and day labels read like the event they belong to.
    expect(Venue::withoutBrandScope()->where('brand_id', $lan->getKey())->pluck('name'))
        ->toContain('SXLan Arena')
        ->and(Venue::withoutBrandScope()->where('brand_id', $fair->getKey())->pluck('name'))
        ->toContain('Xpertise Messehalle');

    expect(EventDay::withoutBrandScope()->where('event_id', $lanEvent->getKey())->pluck('label'))
        ->toContain('Doors & seating')
        ->and(EventDay::withoutBrandScope()->where('event_id', $fairEvent->getKey())->pluck('label'))
        ->toContain('Public day');

    // The roster's second team is the one that differs.
    expect(Team::withoutBrandScope()->where('event_id', $lanEvent->getKey())->pluck('name'))
        ->toContain('Entrance', 'Bar')
        ->and(Team::withoutBrandScope()->where('event_id', $fairEvent->getKey())->pluck('name'))
        ->toContain('Entrance', 'Info desk');

    // Both fairs settle money through the same chain; only the price differs.
    expect(Invoice::withoutBrandScope()->where('brand_id', $fair->getKey())->count())->toBeGreaterThan(0);
});

/**
 * The demo seed's commerce slice runs through the real settlement chain
 * (ADR-0006): a priced Supporter registration must yield an order and an
 * invoice, and the alternating manual payment must leave both paid and
 * pending orders behind. The archetype's invoice-settled trade type — Sponsor
 * at a LAN — adds its own orders on top, and one paid order carries a credit
 * note.
 */
it('seeds orders, invoices and payments through the settlement chain', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 6);

    $brand = seededDemoBrandForTest();
    expect($brand)->not->toBeNull()
        ->and($brand->operator_id)->not->toBeNull();

    // 6 registrations / 3 = 2 supporters (index 0 paid, index 1 pending),
    // plus 2 invoice-settled sponsors.
    $orders = Order::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($orders)->toHaveCount(4)
        ->and($orders->where('status', OrderStatus::Paid))->toHaveCount(1)
        ->and($orders->where('status', OrderStatus::Pending))->toHaveCount(3);

    $invoices = Invoice::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($invoices)->toHaveCount(4)
        ->and($invoices->first()->invoice_number)->toStartWith('SXLAN-');

    // The paid one is corrected by a credit note (ADR-0031), and one open
    // receivable was back-dated and dunned.
    expect(CreditNote::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(1)
        ->and($invoices->where('dunning_level', '>', 0))->not->toBeEmpty();

    // Both settlement-account states, so connect/disconnect is demonstrable.
    $accounts = SettlementAccount::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($accounts)->toHaveCount(2)
        ->and($accounts->whereNotNull('disconnected_at'))->toHaveCount(1);

    // Re-running must not double-submit: commerce seeding is idempotent on
    // the Supporter type's existence, like events are on their slug.
    seedDemoDataForTest($this, registrations: 6);

    expect(Order::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(4)
        ->and(RegistrationType::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('name', 'Supporter')
            ->count())->toBe(1);
});

/**
 * Admissions are issued by Registration's confirmation; this slice adds what
 * the door itself owns, and the check-ins go through `RecordScan` with really
 * signed tokens so accept and reject rows carry genuine reasons.
 */
it('seeds checkpoints, entrance devices and real check-ins', function () {
    releaseLocalhostForTest();

    // Two events on purpose: a second edition is months away, its days do not
    // contain today, and a seed that tried to scan it anyway used to abort the
    // whole run on the signing key's validity window.
    seedDemoDataForTest($this, registrations: 12, events: 2);

    $brand = seededDemoBrandForTest();
    $live = seededDemoEventsForTest($brand)->firstWhere('state', EventLifecycleState::Running);

    expect(EntranceSigningKey::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->whereNull('retired_at')
        ->count())->toBe(1);

    // Every edition gets its doors; only the one standing open gets scans.
    expect(Checkpoint::withoutBrandScope()->where('event_id', $live->getKey())->count())->toBe(2)
        ->and(Checkpoint::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(4);

    $devices = EntranceDevice::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($devices)->toHaveCount(6)
        ->and($devices->whereNotNull('revoked_at'))->toHaveCount(2);

    $checkIns = CheckIn::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($checkIns->where('result', CheckInResult::Accepted))->not->toBeEmpty()
        // A tampered token is rejected on signature, not admitted quietly.
        ->and($checkIns->where('result', CheckInResult::Rejected))->not->toBeEmpty()
        // Every accepted scan is bound to the event day it counted against.
        ->and($checkIns->where('result', CheckInResult::Accepted)->whereNull('event_day_id'))->toBeEmpty();
});
