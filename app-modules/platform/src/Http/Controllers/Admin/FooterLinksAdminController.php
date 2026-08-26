<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateFooterColumns;
use Konventix\Platform\Actions\UpdateFooterLinks;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Data\Blocks\BlockTypeOptionData;
use Konventix\Platform\Data\FooterColumnData;
use Konventix\Platform\Data\FooterColumnsData;
use Konventix\Platform\Data\FooterLinkData;
use Konventix\Platform\Data\FooterLinksData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterColumn;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\ConsoleSelection;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: the footer every brand-facing page renders (ADR-0029).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; the capability is checked here.
 *
 * No `{operator}` parameter, for the same reason the operator settings page
 * has none: the admin host resolves exactly one operator, and a parameter
 * would invent a way to edit somebody else's footer.
 */
class FooterLinksAdminController
{
    public function edit(OperatorContext $context, ConsoleSelection $selection, BlockRegistry $registry): Response
    {
        Gate::authorize(Permission::ManageFooterLinks->value);

        $operator = $context->current() ?? abort(404);

        $brandId = $selection->brandId();
        $brand = $brandId === null ? null : Brand::query()->where('operator_id', $operator->getKey())->find($brandId);

        return Inertia::render('admin/settings/FooterLinks', [
            'operatorLinks' => $this->linksOf($operator, null),
            'brand' => $brand === null ? null : ['id' => $brand->getKey(), 'name' => $brand->name],
            'brandLinks' => $brand === null ? null : $this->linksOf($operator, $brand->getKey()),
            'operatorColumns' => $this->columnsOf($operator, null),
            'brandColumns' => $brand === null ? null : $this->columnsOf($operator, $brand->getKey()),
            // Both footer block types are Platform's own, so no brand needs
            // to be in context to answer whether they're active.
            'types' => array_values(array_map(
                fn ($type): array => BlockTypeOptionData::ofType($type)->toArray(),
                array_intersect_key($registry->editable(), array_flip(['text', 'social-links'])),
            )),
        ]);
    }

    /**
     * @return array<int, FooterLinkData>
     */
    private function linksOf(Operator $operator, ?string $brandId): array
    {
        return FooterLink::query()
            ->where('operator_id', $operator->getKey())
            ->when($brandId === null, fn ($query) => $query->whereNull('brand_id'))
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->inDisplayOrder()
            ->get()
            ->map(fn (FooterLink $link): FooterLinkData => FooterLinkData::ofModel($link))
            ->all();
    }

    /**
     * @return array<int, FooterColumnData>
     */
    private function columnsOf(Operator $operator, ?string $brandId): array
    {
        return FooterColumn::query()
            ->where('operator_id', $operator->getKey())
            ->when($brandId === null, fn ($query) => $query->whereNull('brand_id'))
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->orderBy('position')
            ->get()
            // `items()`, not `toArray()`: the constructor takes actual
            // BlockData instances (StaticPageData::ofModel does the same),
            // and DataCollectionOf's cast only applies through `::from()`.
            ->map(fn (FooterColumn $column): FooterColumnData => new FooterColumnData($column->heading, $column->blocks?->items() ?? []))
            ->all();
    }

    public function update(
        FooterLinksData $data,
        UpdateFooterLinks $updateFooterLinks,
        OperatorContext $context,
    ): RedirectResponse {
        Gate::authorize(Permission::ManageFooterLinks->value);

        $updateFooterLinks->handle($context->current() ?? abort(404), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Footer saved.')]);

        return to_route('admin.settings.footer.edit');
    }

    public function updateColumns(
        FooterColumnsData $data,
        UpdateFooterColumns $updateFooterColumns,
        OperatorContext $context,
    ): RedirectResponse {
        Gate::authorize(Permission::ManageFooterLinks->value);

        $updateFooterColumns->handle($context->current() ?? abort(404), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Footer columns saved.')]);

        return to_route('admin.settings.footer.edit');
    }
}
