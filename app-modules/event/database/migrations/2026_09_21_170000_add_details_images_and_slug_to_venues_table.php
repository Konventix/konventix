<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Venues get a public page (bugfix round 2026-09-21, Task 18): a
     * per-brand slug for its URL, a description, the entrance's own
     * coordinates (the building's were never rendered), how to get there,
     * parking spots and loading zones as typed lists behind
     * `VenuePlacesCast`, a seat plan reference, and an ordered list of
     * media-library pictures with one primary (ADR-0035: ULIDs, no foreign
     * key, the library's `media_usages` tracks the reference).
     *
     * The slug is backfilled from the name, deterministically — creation
     * order decides who keeps the bare slug on a collision, the later one
     * gets `-2`, `-3`, … — so the migration lands the same way on every
     * instance and the unique index can be added in the same step.
     */
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->text('description')->nullable()->after('country_code');
            $table->decimal('entry_latitude', 10, 7)->nullable()->after('longitude');
            $table->decimal('entry_longitude', 10, 7)->nullable()->after('entry_latitude');
            $table->string('seat_plan_reference')->nullable()->after('access_notes');
            $table->text('public_transport')->nullable()->after('seat_plan_reference');
            $table->json('parking_spots')->nullable()->after('public_transport');
            $table->json('loading_zones')->nullable()->after('parking_spots');
            $table->json('image_media_ids')->nullable()->after('loading_zones');
            $table->ulid('primary_media_id')->nullable()->after('image_media_ids');
        });

        /** @var array<string, array<string, true>> $taken */
        $taken = [];

        foreach (DB::table('venues')->orderBy('created_at')->orderBy('id')->get(['id', 'brand_id', 'name']) as $venue) {
            $base = Str::slug((string) $venue->name);
            $base = $base === '' ? 'venue' : $base;
            $slug = $base;
            $suffix = 2;

            while (isset($taken[$venue->brand_id][$slug])) {
                $slug = $base.'-'.$suffix++;
            }

            $taken[$venue->brand_id][$slug] = true;

            DB::table('venues')->where('id', $venue->id)->update(['slug' => $slug]);
        }

        Schema::table('venues', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['brand_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'slug']);
            $table->dropColumn([
                'slug',
                'description',
                'entry_latitude',
                'entry_longitude',
                'seat_plan_reference',
                'public_transport',
                'parking_spots',
                'loading_zones',
                'image_media_ids',
                'primary_media_id',
            ]);
        });
    }
};
