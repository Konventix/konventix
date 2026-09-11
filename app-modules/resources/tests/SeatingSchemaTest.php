<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * The seating schema of ADR-0060 (design §4): three new tables and the
 * columns that turn `resources` into the projection of a plan. The two
 * hand-written unique indexes are exercised through the models in
 * PlanModelTest; this file only pins that the migrations ran and named
 * what the models expect.
 */
it('extends resource_types into a category', function (): void {
    expect(Schema::hasColumns('resource_types', ['description', 'token', 'symbol', 'sort_order', 'default_shape']))->toBeTrue();
});

it('creates the plan, version and claim tables', function (): void {
    expect(Schema::hasColumns('resource_plans', [
        'id', 'brand_id', 'event_id', 'venue_id', 'name', 'floor_label', 'width_mm', 'height_mm', 'background',
        'unit_display', 'snap_step', 'version', 'document', 'published_at', 'origin_id', 'created_by',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('resource_plan_versions', ['id', 'brand_id', 'plan_id', 'version', 'document', 'source', 'created_by', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumns('resource_claims', [
            'id', 'brand_id', 'event_id', 'registration_id', 'registrant_user_id', 'amendment_id',
            'resource_type_ids', 'seating_mode', 'quantity', 'state',
        ]))->toBeTrue();
});

it('turns resources into the projection of a plan', function (): void {
    expect(Schema::hasColumns('resources', [
        'plan_id', 'ref', 'zone_id', 'group_id', 'accessible', 'blocked', 'neighbors', 'origin_id', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('resource_bookings', 'claim_id'))->toBeTrue();
});
