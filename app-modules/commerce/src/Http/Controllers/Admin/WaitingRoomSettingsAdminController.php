<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Konventix\Commerce\Actions\UpdateWaitingRoomSettings;
use Konventix\Commerce\Data\WaitingRoomSettingsData;
use Konventix\Commerce\Enums\Permission;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: a brand's waiting-room settings (ADR-0047 point 5),
 * reached only from the brand edit page's own Waiting room card — see
 * `CommerceServiceProvider::registerBrandFeatures()`. Route middleware
 * already requires the admin host, auth and `platform.access-admin`; the
 * capability checked here is `commerce.manage-products` — the same one
 * `CommerceWaitingRoomGate::guard()` checks for the operator bypass, since
 * both are "runs this brand's shop," not a separate capability of their
 * own.
 */
final class WaitingRoomSettingsAdminController
{
    public function update(Brand $brand, WaitingRoomSettingsData $data, UpdateWaitingRoomSettings $update): RedirectResponse
    {
        Gate::authorize(Permission::ManageProducts->value);

        $update->handle($brand, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Waiting room settings saved.')]);

        return to_route('admin.brands.edit', $brand);
    }
}
