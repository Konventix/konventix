<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\BrandMenuData;
use Konventix\Platform\Data\BrandMenuItemData;
use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\StaticPage;

/**
 * Replaces a brand's whole menu with the submitted entries, in the
 * submitted order — the footer's replace-all shape (`UpdateFooterLinks`).
 * Position is the index among siblings, so a drag in the editor is the
 * same submit as a relabel; there is no reorder endpoint to drift.
 *
 * Runs on the admin host, where no brand is resolved (ADR-0029): every
 * read escapes the brand scope and every row is stamped explicitly.
 */
final class UpdateBrandMenu
{
    /**
     * @return array<int, BrandMenuItem>
     */
    public function handle(Operator $operator, BrandMenuData $data): array
    {
        $this->guardBrand($operator, $data->brandId);
        $this->guardKeys($data);
        $this->guardHierarchy($data);
        $this->guardDestinations($data);

        return DB::transaction(function () use ($data): array {
            BrandMenuItem::withoutBrandScope()->forBrand($data->brandId)->delete();

            $saved = [];
            $ids = [];
            $positions = [];

            // Parents first: a child needs its parent's fresh id. The submitted
            // order is kept within each sibling group either way.
            $ordered = [
                ...array_filter($data->items, fn (BrandMenuItemData $item): bool => $item->parentKey === null),
                ...array_filter($data->items, fn (BrandMenuItemData $item): bool => $item->parentKey !== null),
            ];

            foreach ($ordered as $item) {
                $group = $item->parentKey ?? '';
                $positions[$group] = ($positions[$group] ?? -1) + 1;

                $row = new BrandMenuItem([
                    'parent_id' => $item->parentKey === null ? null : $ids[$item->parentKey],
                    'kind' => $item->kind,
                    'label' => $this->labelOf($item),
                    // Only the column its kind uses is written, so a kind
                    // switch never leaves the other one behind.
                    'static_page_id' => $item->kind === BrandMenuItemKind::Page ? $item->pageId : null,
                    'url' => $item->kind === BrandMenuItemKind::Url ? $item->url : null,
                    'opens_in_new_tab' => $item->kind === BrandMenuItemKind::Url && $item->opensInNewTab,
                    'position' => $positions[$group],
                ]);
                $row->brand_id = $data->brandId;
                $row->save();

                $ids[$item->key] = $row->getKey();
                $saved[] = $row;
            }

            return $saved;
        });
    }

    private function labelOf(BrandMenuItemData $item): ?string
    {
        $label = $item->label === null ? '' : trim($item->label);

        return $label === '' ? null : $label;
    }

    /**
     * A brand's menu may only be edited under the operator that runs it.
     */
    private function guardBrand(Operator $operator, string $brandId): void
    {
        $owned = Brand::query()->whereKey($brandId)->where('operator_id', $operator->getKey())->exists();

        if (! $owned) {
            throw ValidationException::withMessages(['brandId' => __('That brand is not this operator\'s.')]);
        }
    }

    private function guardKeys(BrandMenuData $data): void
    {
        $seen = [];

        foreach ($data->items as $index => $item) {
            if (isset($seen[$item->key])) {
                throw ValidationException::withMessages(["items.{$index}.key" => __('Every entry needs its own key.')]);
            }

            $seen[$item->key] = true;
        }
    }

    /**
     * One level only: a child names a `parent` entry of this same submit,
     * and a parent never sits under a parent.
     */
    private function guardHierarchy(BrandMenuData $data): void
    {
        $parents = [];

        foreach ($data->items as $item) {
            if ($item->kind === BrandMenuItemKind::Parent) {
                $parents[$item->key] = true;
            }
        }

        foreach ($data->items as $index => $item) {
            if ($item->parentKey === null) {
                continue;
            }

            if ($item->kind === BrandMenuItemKind::Parent) {
                throw ValidationException::withMessages(["items.{$index}.parentKey" => __('A group cannot sit inside another group.')]);
            }

            if (! isset($parents[$item->parentKey]) || $item->parentKey === $item->key) {
                throw ValidationException::withMessages(["items.{$index}.parentKey" => __('That group is not in this menu.')]);
            }
        }
    }

    /**
     * Every entry needs the destination its kind implies, and a page entry
     * may only name one of this brand's own pages. Cross-field invariants,
     * because `required_if` cannot see a sibling inside an array of entries.
     */
    private function guardDestinations(BrandMenuData $data): void
    {
        $pageIds = array_values(array_unique(array_filter(array_map(
            fn (BrandMenuItemData $item): ?string => $item->kind === BrandMenuItemKind::Page ? $item->pageId : null,
            $data->items,
        ))));

        $owned = $pageIds === [] ? [] : StaticPage::withoutBrandScope()
            ->forBrand($data->brandId)
            ->whereIn('id', $pageIds)
            ->pluck('id')
            ->all();

        foreach ($data->items as $index => $item) {
            $error = match ($item->kind) {
                BrandMenuItemKind::Page => $item->pageId === null || $item->pageId === ''
                    ? ['pageId', __('Pick a page for this entry.')]
                    : (in_array($item->pageId, $owned, true) ? null : ['pageId', __('That page is not this brand\'s.')]),
                BrandMenuItemKind::Url => $item->url === null || trim($item->url) === ''
                    ? ['url', __('This entry needs a destination.')]
                    : null,
                BrandMenuItemKind::Parent => $this->labelOf($item) === null
                    ? ['label', __('A group needs a label.')]
                    : null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(["items.{$index}.{$error[0]}" => $error[1]]);
            }
        }
    }
}
