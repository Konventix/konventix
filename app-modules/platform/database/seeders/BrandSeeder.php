<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * The local-dev brand. Every web request must resolve a brand (ADR-0001), so
 * a fresh dev environment needs one answering on localhost before the app
 * renders anything at all.
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $operator = Operator::query()->firstOrCreate(
            ['name' => 'Konventix Dev Operator'],
            ['legal_name' => 'Konventix Dev Operator', 'contact_email' => 'operator@example.com'],
        );

        // The operator is set before the insert, not after: `brands.operator_id`
        // is NOT NULL, so associating afterwards would never survive it. Via
        // the relation rather than a mass-assigned `operator_id`, which stays
        // out of `$fillable` deliberately — nothing arriving from a request
        // should be able to move a brand to another operator.
        $brand = Brand::query()->firstOrNew(['slug' => 'dev']);

        if (! $brand->exists) {
            $brand->fill(['name' => 'Konventix Dev', 'theme_tokens' => []]);
            $brand->operator()->associate($operator);
            $brand->save();
        }

        // Explicit rather than relying on the model's `creating` hook: the
        // host seeder runs under WithoutModelEvents, which mutes it.
        if ($brand->reverb_app_key === null) {
            $brand->forceFill([
                'reverb_app_key' => Str::random(40),
                'reverb_app_secret' => Str::random(40),
            ])->save();
        }

        foreach (['localhost', '127.0.0.1'] as $host) {
            $brand->domains()->firstOrCreate(
                ['host' => $host],
                ['is_primary' => $host === 'localhost'],
            );
        }
    }
}
