<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Konventix\Resources\Actions\ExportPlan;
use Konventix\Resources\Actions\ImportPlan;
use Konventix\Resources\Actions\PublishPlan;
use Konventix\Resources\Console\PlanImportCommand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\PlanVersion;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Plans\PlanCanonicalJson;

/**
 * Design §6.4: export is canonical and stable; import runs a whole document
 * through `SavePlan` with source `import`, accepts unknown ids as new and
 * refuses ids that exist under another plan or brand. The committed example
 * (10 groups × 16 places) is the fallback for building the SXLan plan.
 */
const SEATING_EXAMPLE = 'app-modules/resources/database/seeders/plans/example-160.json';

function seatingExampleDocument(): array
{
    return PlanCanonicalJson::decode((string) file_get_contents(base_path(SEATING_EXAMPLE)));
}

it('exports the document as canonical, stable JSON', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    $b = seatingPlace($type->id, 'A-02', ['x' => 800, 'rotation' => 90]);
    seatingSave($plan, seatingDocument([$b, $a]));

    $json = app(ExportPlan::class)->handle($plan);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($decoded))->toBe(['elements', 'schema'])
        ->and($decoded['schema'])->toBe('konventix.plan/v1')
        ->and($decoded['elements'][0]['id'])->toBe($a['id'])
        ->and($decoded['elements'][1]['rotation'])->toBe(90)
        ->and($decoded['elements'][0]['rotation'])->toBeInt();

    seatingSave($plan, seatingDocument([$a, $b]));

    expect(app(ExportPlan::class)->handle($plan))->toBe($json);
});

it('imports the example document as 160 places with unique refs', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $result = app(ImportPlan::class)->handle($plan, seatingExampleDocument(), null, [
        PlanImportCommand::PLACEHOLDER_CATEGORY => $type->id,
    ]);
    $places = seatingPlaces($plan);
    $refs = $places->pluck('ref')->sort()->values();

    expect($result->version)->toBe(1)
        ->and($places)->toHaveCount(160)
        ->and($refs->unique())->toHaveCount(160)
        ->and($refs->first())->toBe('A-01')
        ->and($refs->last())->toBe('J-16')
        ->and($places->every(fn (Resource $place): bool => $place->resource_type_id === $type->id
            && $place->zone_id !== null
            && $place->group_id !== null
            && $place->is_published === false
            && $place->blocked === false))->toBeTrue()
        ->and(PlanVersion::withoutBrandScope()->where('plan_id', $plan->id)->firstOrFail()->getAttributes()['source'])->toBe('import');

    app(PublishPlan::class)->handle($plan);

    expect(seatingPlaces($plan)->every(fn (Resource $place): bool => $place->is_published))->toBeTrue();
});

it('holds a place obtained through the import path', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    app(ImportPlan::class)->handle($plan, seatingExampleDocument(), null, [
        PlanImportCommand::PLACEHOLDER_CATEGORY => $type->id,
    ]);
    app(PublishPlan::class)->handle($plan);

    $place = seatingPlaces($plan)->sortBy('ref')->first();
    $window = seatingWindow($eventId, $brand->id);

    $booking = seatingHold($place, $window);

    expect($booking->state)->toBe(BookingState::Held)
        ->and($booking->resource_id)->toBe($place->id);
});

it('imports and exports through the artisan commands', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    seatingCategory($eventId, 'seat');

    $this->artisan('resources:plan-import', [
        'file' => SEATING_EXAMPLE,
        '--event' => $eventId,
        '--name' => 'Hall 1',
        '--brand' => $brand->slug,
        '--category' => 'seat',
        '--publish' => true,
    ])->expectsOutputToContain('160 place(s)')->assertSuccessful();

    $plan = Plan::query()->where('name', 'Hall 1')->firstOrFail();

    expect($plan->event_id)->toBe($eventId)
        ->and($plan->brand_id)->toBe($brand->id)
        ->and($plan->version)->toBe(1)
        ->and($plan->published_at)->not->toBeNull()
        ->and($plan->width_mm)->toBeGreaterThanOrEqual(19000)
        ->and($plan->height_mm)->toBeGreaterThanOrEqual(23000)
        ->and(Resource::query()->where('plan_id', $plan->id)->where('is_published', true)->count())->toBe(160);

    $this->artisan('resources:plan-export', ['plan' => $plan->id])
        ->expectsOutputToContain('"schema": "konventix.plan/v1"')
        ->assertSuccessful();
});

it('refuses to import ids that already exist under another plan', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId, 'seat');
    $first = seatingPlan($eventId, ['name' => 'Hall 1']);
    $map = [PlanImportCommand::PLACEHOLDER_CATEGORY => $type->id];

    app(ImportPlan::class)->handle($first, seatingExampleDocument(), null, $map);

    $second = seatingPlan($eventId, ['name' => 'Hall 2']);

    try {
        app(ImportPlan::class)->handle($second, seatingExampleDocument(), null, $map);
        $this->fail('Expected ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveCount(160)
            ->and(array_keys($e->errors()))->each->toMatch('/^document\.elements\.\d+\.id$/');
    }

    expect(seatingPlaces($second))->toHaveCount(0)
        ->and($second->fresh()->version)->toBe(0);

    $this->artisan('resources:plan-import', [
        'file' => SEATING_EXAMPLE,
        '--event' => $eventId,
        '--name' => 'Hall 3',
        '--category' => 'seat',
    ])->expectsOutputToContain('another plan or brand')->assertFailed();

    expect(Plan::query()->where('name', 'Hall 3')->exists())->toBeFalse();
});

it('refuses an import whose categories the event does not know', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    seatingCategory($eventId, 'seat');
    seatingCategory($eventId, 'vip');

    $this->artisan('resources:plan-import', [
        'file' => SEATING_EXAMPLE,
        '--event' => $eventId,
        '--name' => 'Hall 1',
    ])->expectsOutputToContain('--category')->assertFailed();

    expect(Plan::query()->where('name', 'Hall 1')->exists())->toBeFalse();
});
