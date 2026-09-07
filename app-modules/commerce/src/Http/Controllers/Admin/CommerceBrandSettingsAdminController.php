<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Konventix\Commerce\Actions\UpdateCommerceBrandSettings;
use Konventix\Commerce\Data\CommerceBrandSettingsData;
use Konventix\Commerce\Enums\Permission;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: a brand's Commerce settings (ADR-0057 point 4) — today
 * the repayment window a reversed payment gets. Rendered beside the brand's
 * settlement accounts, because that is where the money that came back left
 * from; the route middleware already requires the admin host, auth and
 * `platform.access-admin`, and the capability checked here is
 * `commerce.manage-settlement-account`, the same one the page it sits on
 * requires: deciding how long a chargeback may go unpaid is treasury
 * policy, not day-to-day shop work.
 */
final class CommerceBrandSettingsAdminController
{
    public function update(Brand $brand, CommerceBrandSettingsData $data, UpdateCommerceBrandSettings $update): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $update->handle($brand, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Commerce settings saved.')]);

        return to_route('admin.settlement-accounts.index');
    }
}
