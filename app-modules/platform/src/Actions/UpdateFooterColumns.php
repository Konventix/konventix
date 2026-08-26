<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Konventix\Platform\Data\Blocks\BlockData;
use Konventix\Platform\Data\FooterColumnsData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterColumn;
use Konventix\Platform\Models\Operator;

/**
 * Replaces one of the operator's footer column sets — operator-wide, or one
 * brand's own — with the submitted columns, in the submitted order. The
 * same replace-all shape as {@see UpdateFooterLinks}, and each column's
 * block list is validated through the same block composition pipeline a
 * static page uses, restricted to the block types that make sense in a
 * footer.
 */
final class UpdateFooterColumns
{
    use ValidatesBlockComposition;

    private const array FOOTER_BLOCKS = ['text', 'social-links'];

    public function __construct(private readonly BlockRegistry $registry) {}

    /** @return list<FooterColumn> */
    public function handle(Operator $operator, FooterColumnsData $data): array
    {
        $this->guardBrand($operator, $data->brandId);
        $brand = $data->brandId === null ? null : Brand::query()->find($data->brandId);

        $columns = $this->withinBrand($brand, function () use ($data): array {
            $validated = [];

            foreach (array_values($data->columns) as $index => $column) {
                $validated[] = [
                    'heading' => $column->heading,
                    'blocks' => $this->validateBlocks(
                        $this->registry,
                        array_values(array_map(fn (BlockData $block): array => $block->toArray(), $column->blocks)),
                        self::FOOTER_BLOCKS,
                        "columns.{$index}.blocks",
                    ),
                ];
            }

            return $validated;
        });

        return DB::transaction(function () use ($operator, $data, $columns): array {
            FooterColumn::query()
                ->where('operator_id', $operator->getKey())
                ->when($data->brandId === null, fn ($q) => $q->whereNull('brand_id'))
                ->when($data->brandId !== null, fn ($q) => $q->where('brand_id', $data->brandId))
                ->delete();

            $saved = [];

            foreach ($columns as $position => $column) {
                $row = new FooterColumn(['heading' => $column['heading'], 'blocks' => $column['blocks'], 'position' => $position]);
                $row->operator_id = $operator->getKey();
                $row->brand_id = $data->brandId;
                $row->save();
                $saved[] = $row;
            }

            return $saved;
        });
    }

    /**
     * A brand's columns may only be edited under the operator that runs it.
     */
    private function guardBrand(Operator $operator, ?string $brandId): void
    {
        if ($brandId === null) {
            return;
        }

        $owned = Brand::query()->whereKey($brandId)->where('operator_id', $operator->getKey())->exists();

        if (! $owned) {
            throw ValidationException::withMessages(['brandId' => __('That brand is not this operator\'s.')]);
        }
    }
}
