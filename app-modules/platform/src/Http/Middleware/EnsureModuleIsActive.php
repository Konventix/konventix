<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Modules\ModuleGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforcement point 1 of 4 for per-brand module activation (ADR-0003).
 *
 * Registered as the `ensure-module` route middleware alias and applied
 * automatically by ModuleServiceProvider to every gateable module's routes —
 * modules do not opt in by hand.
 *
 * The other three points are navigation (NavigationRegistry), UI slots
 * (SlotRegistry), and listeners (each module short-circuits its own).
 */
class EnsureModuleIsActive
{
    public function __construct(private readonly ModuleGate $gate) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->gate->isActive($module), 404);

        return $next($request);
    }
}
