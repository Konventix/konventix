<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Database\Factories\FooterColumnFactory;
use Spatie\LaravelData\DataCollection;

/**
 * One column of the operator's footer: a heading and a small block
 * composition, edited with the same block editor a static page uses
 * (slice 21b).
 *
 * Operator-owned, like `FooterLink`, on purpose — not `BelongsToBrand`. A
 * nullable `brand_id` narrows an operator-owned list to one brand's own
 * columns, it does not scope one (ADR-0010's distinction, see the
 * migration).
 *
 * @property string $id
 * @property string $operator_id
 * @property string|null $brand_id
 * @property string|null $heading
 * @property DataCollection<int, BlockData>|null $blocks
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FooterColumn extends Model
{
    /** @use HasFactory<FooterColumnFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['heading', 'blocks', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => DataCollection::class.':'.BlockData::class,
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    protected static function newFactory(): FooterColumnFactory
    {
        return FooterColumnFactory::new();
    }
}
