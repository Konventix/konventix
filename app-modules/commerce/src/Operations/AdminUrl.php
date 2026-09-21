<?php

declare(strict_types=1);

namespace Konventix\Commerce\Operations;

use Konventix\Platform\Operators\OperatorSettings;

/**
 * The one rule for a link that points a shop operator into the console
 * (ADR-0048 point 3), shared by every `commerce.operations` producer —
 * the four `NotifyOperatorsOn*` listeners, the sweep's
 * `AsksThePspBeforeCancelling` and `ReconcilePayments` (bugfix round
 * 2026-09-21, Task 9; five private copies of it before).
 *
 * The link is always absolute. The operator's own console origin
 * (`OperatorSettings::adminBaseUrlForBrand()`, `scheme://{admin_host}`)
 * when the operator has set one; `config('app.url')` otherwise. A
 * single-host install or a dev instance has no `admin_host`, and the bare
 * `/admin/orders/{id}` the producers stored for it opened on whatever host
 * the reader happened to be on — a brand host, which rejects the console
 * path. The console is reached through Platform's sanctioned doorway, not
 * the `Operator` model (ADR-0004's allow-list).
 */
final class AdminUrl
{
    public function __construct(private readonly OperatorSettings $operatorSettings) {}

    /**
     * @param  array<string, string>  $parameters
     */
    public function to(string $brandId, string $routeName, array $parameters = []): string
    {
        $base = $this->operatorSettings->adminBaseUrlForBrand($brandId)
            ?? rtrim((string) config('app.url'), '/');

        if ($base === '') {
            return route($routeName, $parameters, absolute: true);
        }

        return rtrim($base, '/').route($routeName, $parameters, absolute: false);
    }
}
