<?php

declare(strict_types=1);

namespace Konventix\Support\Context;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Support\Data\SupportClientContextData;
use Konventix\Support\Data\SupportContextData;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Where the trust boundary of design §5 / D-SUP-04 is actually applied: the
 * browser's half of the context arrives as a
 * {@see SupportClientContextData} and comes out of here as a full
 * {@see SupportContextData} in which **every field the server can derive
 * itself has been overwritten by the server's answer**.
 *
 * That is the whole rule, and it is worth stating why it is a class rather
 * than four lines in the controller. A report is read later as evidence:
 * "this happened on release 0.8.0, on the checkout route, on brand sxlan,
 * for this account". If any of that could be dictated by whoever posted the
 * form, the evidence is worthless — and a report is the one endpoint in the
 * application a stranger may write to. So the client is believed about
 * exactly what only the browser can know (the URL it was on, the visits
 * before it, the viewport, the last JavaScript error, the page's own
 * correlation id) and about nothing else.
 *
 * The one field that looks client-derived and is not: `route`. The browser
 * knows its URL; the server knows what that URL *means*, so the route name
 * is resolved here against this application's own route table rather than
 * taken from a prop. A URL that resolves to nothing we own yields null,
 * which is the honest answer for a link somebody pasted.
 */
final class SupportContextFactory
{
    /**
     * Laravel's `Context` key the host's `App\Observability\CorrelationId`
     * writes the id under (ADR-0056).
     *
     * A literal rather than an import: a module may not depend on the host
     * application layer (`ArchitectureTest`: no `use App\…` anywhere under
     * `app-modules/`). Same deliberate copy, same reasoning, as
     * {@see SupportContextData::CORRELATION_ID}; if the host ever renames
     * the key, this reads null and a report loses its submit id rather
     * than picking up somebody else's.
     */
    private const string CORRELATION_CONTEXT_KEY = 'correlation_id';

    /** A user agent is a fingerprint, not a document (design §5). */
    private const int USER_AGENT_MAX = 512;

    public function build(Request $request, SupportClientContextData $client): SupportContextData
    {
        $brand = app(BrandContext::class)->current();
        $matched = $this->resolveReportedRoute($client->url);
        $user = $request->user();
        $submitCorrelationId = Context::get(self::CORRELATION_CONTEXT_KEY);

        return new SupportContextData(
            // Client, already regex-validated by SupportClientContextData.
            pageCorrelationId: $client->pageCorrelationId,
            // Server, authoritative: the POST that carried the report.
            submitCorrelationId: is_string($submitCorrelationId) ? $submitCorrelationId : null,
            url: $client->url,
            route: $matched['route'],
            previousUrl: $client->previousUrl,
            previousCorrelationId: $client->previousCorrelationId,
            recentVisits: $client->recentVisits,
            release: $this->configString('app.version'),
            // Null until the image build passes a sha (flagged, design §14).
            commit: $this->configString('app.commit'),
            // No brand in context means the operator console (ADR-0063),
            // which is a place and not an absence — the label says so.
            brand: $brand === null ? SupportContextData::CONSOLE_BRAND : $brand->slug,
            event: $matched['event'],
            userId: $user?->getAuthIdentifier() === null ? null : (string) $user->getAuthIdentifier(),
            locale: $this->userAttribute($user, 'locale'),
            timezone: $this->userAttribute($user, 'timezone'),
            userAgent: $this->truncate($request->userAgent(), self::USER_AGENT_MAX),
            viewport: $client->viewport,
            clientSentAt: $client->clientSentAt,
            receivedAt: now()->toIso8601String(),
            lastClientError: $client->lastClientError,
        );
    }

    /**
     * What the reported URL resolves to in this application's route table:
     * the route's name, and its `event` parameter when it has one (a slug
     * or an id — route model binding is middleware and has not run for a
     * synthetic request, so this is the raw segment, which is what design
     * §5 asks for).
     *
     * Everything that can go wrong here is the same kind of wrong: the URL
     * belongs to another host, names a route that no longer exists, or is
     * not a URL at all. None of that may cost the person their report, so
     * the whole resolution is best-effort and answers nulls.
     *
     * @return array{route: string|null, event: string|null}
     */
    private function resolveReportedRoute(?string $url): array
    {
        if ($url === null || $url === '') {
            return ['route' => null, 'event' => null];
        }

        try {
            $probe = Request::create($url, 'GET');
            $route = Route::getRoutes()->match($probe);

            $event = $route->parameter('event');

            return [
                'route' => $route->getName(),
                'event' => is_string($event) ? $event : null,
            ];
        } catch (HttpException) {
            // No such route on this installation — a pasted link, a page
            // that has since been removed.
            return ['route' => null, 'event' => null];
        } catch (Throwable) {
            // Not a URL the framework can even build a request from.
            return ['route' => null, 'event' => null];
        }
    }

    /**
     * A user attribute without importing Identity's model (ADR-0004): the
     * guard is the authenticated contract, the attributes live on the
     * Eloquent model behind it, and this module may know about neither.
     */
    private function userAttribute(mixed $user, string $attribute): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }

        $value = $user->getAttribute($attribute);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : Str::limit($value, $length, '');
    }
}
