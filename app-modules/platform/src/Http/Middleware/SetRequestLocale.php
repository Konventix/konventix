<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Locales\LocaleResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Computes the effective locale once per request (ADR-0068 §4) and sets
 * it on the application, so `__()`, Blade's `app()->getLocale()` in the
 * root view and Inertia's shared `locale` all agree. Runs after
 * ResolveBrand and after the session/auth stack, because it needs the
 * brand and the person.
 */
final class SetRequestLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->forRequest($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
