<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateFooterLinks;
use Konventix\Platform\Data\FooterLinkData;
use Konventix\Platform\Data\FooterLinksData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
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
    public function edit(OperatorContext $context, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ManageFooterLinks->value);

        $operator = $context->current() ?? abort(404);

        $brandId = $selection->brandId();
        $brand = $brandId === null ? null : Brand::query()->where('operator_id', $operator->getKey())->find($brandId);

        return Inertia::render('admin/settings/FooterLinks', [
            'operatorLinks' => $this->linksOf($operator, null),
            'brand' => $brand === null ? null : ['id' => $brand->getKey(), 'name' => $brand->name],
            'brandLinks' => $brand === null ? null : $this->linksOf($operator, $brand->getKey()),
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
}
