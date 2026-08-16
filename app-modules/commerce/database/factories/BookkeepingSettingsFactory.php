<?php

declare(strict_types=1);

namespace Konventix\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Commerce\Models\BookkeepingSettings;

/**
 * @extends Factory<BookkeepingSettings>
 */
class BookkeepingSettingsFactory extends Factory
{
    /** @var class-string<BookkeepingSettings> */
    protected $model = BookkeepingSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_id' => Str::ulid()->toBase32(),
            'consultant_number' => 1001,
            'client_number' => 456,
        ];
    }
}
