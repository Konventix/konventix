<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Realtime\BrandBroadcasting;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request host into either a brand (public surface, ADR-0001) or
 * the operator's admin console (ADR-0029), and binds the result into the
 * container. Everything downstream — the BelongsToBrand global scope, theme
 * token injection, Pennant gating, capability checks — reads from that
 * context.
 *
 * A host that matches neither is a 404, not an unscoped request. The spatie
 * permission team is the scope id of whatever was resolved: the brand's ULID
 * on brand hosts, the operator's ULID on the admin host (ADR-0029).
 */
class ResolveBrand
{
    public function __construct(
        private readonly BrandContext $context,
        private readonly OperatorContext $operatorContext,
        private readonly BrandBroadcasting $broadcasting,
        private readonly ViewFactory $view,
        private readonly ConfigRepository $config,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        $operator = Operator::query()->where('admin_host', $host)->first();

        if ($operator instanceof Operator) {
            return $this->handleAsOperator($operator, $request, $next);
        }

        $brand = $this->resolve($host);

        abort_if($brand === null, 404);

        // Mutually exclusive contexts: a request runs as a brand or as the
        // operator, never both. Explicit forgets keep that true even when a
        // context instance survives across requests (tests, workers).
        $this->operatorContext->forget();
        $this->context->set($brand);
        $this->permissions->setPermissionsTeamId($brand->getKey());

        // Brand-scoped sessions (ADR-0001): the cookie carries the brand slug,
        // so even if SESSION_DOMAIN is ever widened to an apex that spans two
        // brands' subdomains, one brand's session cookie can never be read as
        // another's. Runs before StartSession because this middleware is
        // prepended to the `web` group.
        $this->config->set(
            'session.cookie',
            Str::slug((string) config('app.name'), '_').'_'.$brand->slug.'_session',
        );

        // Broadcasts fired during this request sign with this brand's Reverb
        // app (ADR-0025), and the root template gets the brand's semantic
        // token overrides (ADR-0008).
        $this->broadcasting->useBrand($brand);
        $this->view->share('brandTokens', $brand->themeTokens());

        return $next($request);
    }

    /**
     * ADR-0029: the admin host runs as the operator, with no brand. BrandScope
     * hardens brand-owned queries to empty on this path, so admin code spans
     * brands only through the explicit withoutBrandScope() escape hatch.
     */
    private function handleAsOperator(Operator $operator, Request $request, Closure $next): Response
    {
        $this->context->forget();
        $this->operatorContext->set($operator);
        $this->permissions->setPermissionsTeamId($operator->getKey());

        $this->config->set(
            'session.cookie',
            Str::slug((string) config('app.name'), '_').'_operator_session',
        );

        $this->view->share('brandTokens', []);

        return $next($request);
    }

    private function resolve(string $host): ?Brand
    {
        return Brand::query()
            ->whereHas('domains', fn ($query) => $query->where('host', $host))
            ->first();
    }
}
