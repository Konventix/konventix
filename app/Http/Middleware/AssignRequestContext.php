<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group, so it runs after ResolveBrand and after the
 * session is open: the two facts it records exist only then. Route and
 * release are set earlier, on RouteMatched, for every request including
 * the API ones this group never sees.
 */
final class AssignRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::forBrand(app(BrandContext::class)->current()?->slug);

        $userId = $request->user()?->getAuthIdentifier();
        RequestContext::forUser($userId === null ? null : (string) $userId);

        return $next($request);
    }
}
