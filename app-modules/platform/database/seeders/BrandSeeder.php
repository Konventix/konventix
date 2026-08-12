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

        $brand = Brand::query()->firstOrCreate(
            ['slug' => 'dev'],
            ['name' => 'Konventix Dev', 'theme_tokens' => []],
        );

        if ($brand->operator_id === null) {
            $brand->operator()->associate($operator)->save();
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
