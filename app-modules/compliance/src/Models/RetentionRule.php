<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\RetentionRuleFactory;
use Konventix\Compliance\Enums\RetentionClockStart;

/**
 * How long one category of data may be kept (SPEC "Owns").
 *
 * Compliance publishes the rule and knows nothing about the tables behind
 * the category; the owning module recognises its own category names and
 * deletes its own rows when `RetentionPeriodElapsed` arrives. A rule that
 * named a table would put Compliance inside another module's schema, which
 * is the one thing this module must never do (ADR-0004).
 *
 * No `BelongsToBrand`: a null `brand_id` is an operator-wide rule, and the
 * console that administers rules runs on the brand-less admin host.
 *
 * @property string $id
 * @property string|null $brand_id
 * @property string $data_category
 * @property int $retention_months
 * @property RetentionClockStart $clock_start
 * @property string|null $description
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RetentionRule extends Model
{
    /** @use HasFactory<RetentionRuleFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'brand_id',
        'data_category',
        'retention_months',
        'clock_start',
        'description',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_start' => RetentionClockStart::class,
            'retention_months' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function newFactory(): RetentionRuleFactory
    {
        return RetentionRuleFactory::new();
    }
}
