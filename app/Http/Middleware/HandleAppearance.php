<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Konventix\Platform\Cookies\CookiePreferences;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Functional storage refused: the `appearance` cookie is not trusted
        // even if one is still sitting on the device from before the choice
        // was made — `system` renders until the visitor opts back in.
        View::share('appearance', CookiePreferences::fromRequest($request) === false
            ? 'system'
            : ($request->cookie('appearance') ?? 'system'));

        return $next($request);
    }
}
