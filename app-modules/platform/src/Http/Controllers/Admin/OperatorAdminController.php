<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateOperatorSettings;
use Konventix\Platform\Data\OperatorSettingsData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator console: the operator's own settings (ADR-0029, ADR-0030).
 *
 * The operator being edited is always the one the admin host resolved — never
 * an id from the request. There is exactly one operator per host by
 * construction (ADR-0001), so a route parameter would be inventing a way to
 * edit a different one.
 */
class OperatorAdminController
{
    public function __construct(private readonly OperatorContext $context) {}

    public function edit(): Response
    {
        Gate::authorize(Permission::ManageOperator->value);

        return Inertia::render('admin/settings/Operator', [
            'operator' => OperatorSettingsData::ofModel($this->operator()),
        ]);
    }

    public function update(OperatorSettingsData $data, UpdateOperatorSettings $updateOperatorSettings): RedirectResponse
    {
        Gate::authorize(Permission::ManageOperator->value);

        $updateOperatorSettings->handle($this->operator(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Operator settings updated.')]);

        return to_route('admin.settings.operator.edit');
    }

    /**
     * The route group already requires the operator host, so this is belt and
     * braces rather than a real branch — but returning 404 beats a null
     * dereference if that ever stops being true.
     */
    private function operator(): Operator
    {
        return $this->context->current() ?? throw new NotFoundHttpException;
    }
}
