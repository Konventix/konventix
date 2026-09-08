<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces a non-string credential field with an empty string before anything
 * reads it (review finding 6 on #253).
 *
 * `email[]=a&email[]=b` on `/register` or `/reset-password` reaches Fortify's
 * own controllers, which call `Str::lower($request->email)` when
 * `fortify.lowercase_usernames` is on — `mb_strtolower()` then raises a
 * TypeError and the route answers 500. Unauthenticated, with nothing but a
 * CSRF token. The rate limiter closures had the same problem one layer out and
 * are fixed at the source (`IdentityServiceProvider::submittedAddress()`), but
 * the vendor controllers are not ours to change.
 *
 * Emptying rather than aborting on purpose: there is no sane address in an
 * array of them, and an empty value takes the ordinary validation path, so the
 * caller gets the same "the email field is required" answer as any other
 * malformed submission instead of a shape that says something about the
 * endpoint.
 */
final class EnsureCredentialsAreStrings
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach (array_unique(['email', Fortify::username()]) as $field) {
            $value = $request->input($field);

            if ($value !== null && ! is_string($value)) {
                $request->merge([$field => '']);
            }
        }

        return $next($request);
    }
}
