<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0047's route enforcement point: a per-brand feature with no module gate
 * behind it. Registered as the `ensure-feature` route middleware alias
 * beside `ensure-module` — a route names the feature it needs, e.g.
 * `ensure-feature:commerce:shop`.
 *
 * With no brand resolved (console, queue, admin host) the feature reads as
 * inactive: refusing to act is the safe default, same as ModuleGate.
 */
class EnsureFeatureIsActive
{
    public function __construct(private readonly BrandContext $context) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $brand = $this->context->current();

        abort_unless($brand !== null && Feature::for($brand)->active($feature), 404);

        return $next($request);
    }
}
