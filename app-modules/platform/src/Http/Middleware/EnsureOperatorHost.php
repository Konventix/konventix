<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Operators\OperatorContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin routes exist only on the operator's admin host (ADR-0029). On a brand
 * host they are a 404 — not a 403, because their existence is nobody's
 * business there.
 */
class EnsureOperatorHost
{
    public function __construct(private readonly OperatorContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->hasOperator(), 404);

        return $next($request);
    }
}
