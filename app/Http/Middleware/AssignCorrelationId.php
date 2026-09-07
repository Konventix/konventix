<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request its correlation ID (ADR-0056) and answers with it.
 *
 * First on the global stack, before the router: a request the router turns
 * away with a 404, a health probe and a PSP webhook all get one, and so does
 * every log line ResolveBrand or the exception handler writes on the way.
 */
final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::accept($request->headers->get(CorrelationId::HEADER));

        CorrelationId::assign($id);

        // One value for the whole request: whatever reads the header from
        // here on sees the ID that was actually assigned, not the raw input.
        $request->headers->set(CorrelationId::HEADER, $id);

        $response = $next($request);

        $response->headers->set(CorrelationId::HEADER, $id);

        return $response;
    }
}
