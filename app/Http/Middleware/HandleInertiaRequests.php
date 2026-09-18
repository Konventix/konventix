<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\CorrelationId;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Konventix\Commerce\Cart\CartSummary;
use Konventix\Identity\Profile\AvatarUrlResolver;
use Konventix\Platform\Blocks\BlockCompositionRenderer;
use Konventix\Platform\Branding\BrandIdentityResolver;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Cookies\CookiePreferences;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Data\BrandIdentityData;
use Konventix\Platform\Data\FooterLinkData;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Enums\Permission as PlatformPermission;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterColumn;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\ConsoleSelection;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $identity = $this->identity();

        return [
            ...parent::share($request),
            // The resolved brand's name on a brand host, the operator's on
            // the admin host — never the deployment's application name,
            // which is nobody's public identity (ADR-0001).
            'name' => $identity->name,
            // Logo and favicon URLs for the shell; every one of them may be
            // null, and the shell falls back to the bundled mark.
            'brand' => $identity,
            'auth' => [
                // The five-field shape Identity's SPEC promises (ADR-0051 §5):
                // ULID, display name, avatar URL, locale, timezone — never
                // the whole model. The zone is here rather than on each page
                // because it is what every page's timestamps are rendered in
                // (#243), and a page that had to ask for it would be a page
                // that could forget to.
                'user' => $request->user() === null ? null : [
                    'id' => (string) $request->user()->getAuthIdentifier(),
                    'name' => $request->user()->name,
                    'avatarUrl' => app(AvatarUrlResolver::class)->forUser((string) $request->user()->getAuthIdentifier()),
                    'locale' => $request->user()->locale,
                    'timezone' => $request->user()->timezone,
                ],
            ],
            // The current brand's Reverb app key (ADR-0025). Echo is
            // configured from this, because the key differs per brand and
            // therefore cannot be a build-time VITE constant.
            'reverb' => [
                'key' => app(BrandContext::class)->current()?->reverb_app_key,
            ],
            // The brand shell's primary navigation: modules contribute
            // entries from their own providers and the registry filters by
            // module activation and capability at render time (ADR-0003,
            // enforcement point 2). Absent on the admin host, which has its
            // own `admin.navigation`.
            'nav' => app(BrandContext::class)->current() !== null
                ? app(NavigationRegistry::class)
                    ->visible(NavigationItem::AREA_BRAND)
                    ->map(fn (NavigationItem $item): array => $item->toArray())
                    ->all()
                : [],
            // The signed-in account's own entries, rendered in the account
            // menu rather than the topbar. Empty for a guest, so the menu
            // that never renders for one costs no queries either.
            'accountNav' => $request->user() !== null && app(BrandContext::class)->current() !== null
                ? app(NavigationRegistry::class)
                    ->visible(NavigationItem::AREA_ACCOUNT)
                    ->map(fn (NavigationItem $item): array => $item->toArray())
                    ->all()
                : [],
            // Operator console shell data (ADR-0029): only present on the
            // admin host, capability-filtered by the registry.
            'admin' => app(OperatorContext::class)->hasOperator() ? [
                'operatorName' => app(OperatorContext::class)->current()?->name,
                'navigation' => app(NavigationRegistry::class)
                    ->visible(NavigationItem::AREA_ADMIN)
                    ->map(fn (NavigationItem $item): array => $item->toArray())
                    ->all(),
                // In the user's own drag order (ADR-0042), not alphabetical.
                'favorites' => $request->user()
                    ? AdminFavorite::query()
                        ->where('user_id', $request->user()->getKey())
                        ->inUserOrder()
                        ->get(['id', 'label', 'url', 'position'])
                        ->map(fn (AdminFavorite $favorite): array => [
                            'id' => $favorite->getKey(),
                            'label' => $favorite->label,
                            'url' => $favorite->url,
                        ])
                        ->all()
                    : [],
                'selection' => $this->consoleSelection(),
                // Whether the media picker may offer its own upload (#292).
                // The route checks the capability again; this only decides
                // whether the button is drawn.
                'canManageMedia' => $request->user()?->can(PlatformPermission::ManageMedia->value) ?? false,
            ] : null,
            // The operator's footer, rendered by the brand shell on every
            // page (the operator console has its own chrome and renders
            // none of this).
            'footer' => [
                'links' => $this->footerLinks(),
                'columns' => $this->footerColumns(),
                'version' => config('app.version'),
            ],
            // The corner ribbon a non-production instance wears (GitLab #68):
            // null on production, and the shell renders nothing then. The
            // decision is the server's, never the component's.
            'environment' => app(DeploymentEnvironment::class)->ribbon(),
            // This response's correlation ID (ADR-0056), the same value as
            // its `X-Request-ID` header: what a report from the browser
            // (#88) quotes so that the log lines behind it can be found.
            // Shared rather than rendered into the root template, because
            // an Inertia visit replaces the props and not the document.
            'correlationId' => CorrelationId::current(),
            // How this operator writes a date and a time (#242). Shared
            // rather than per page for the same reason the reader's zone is:
            // `resources/js/lib/datetime.ts` is the only consumer, and a page
            // that had to pass the setting along is a page that could forget.
            'dateTimeFormat' => $this->dateTimeFormat(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The topbar's cart badge (ADR-0047, Task 11). The query itself
            // lives behind Commerce's own `CartSummary` service — the host
            // layer resolves it by name and never reads a `Cart` row or
            // learns what one is, the same way it already resolves
            // Platform's own registries (`ArchitectureTest`'s dependency
            // check only flags a real type-hinted dependency, never a
            // service resolved through `app()`).
            'cart' => [
                'count' => app(CartSummary::class)->countFor($request),
            ],
            // What the visitor decided about storage on their device
            // (slice 21b), and what each module declares it stores — the
            // cookie-settings dialog renders from this and nothing else.
            'cookies' => [
                'functional' => CookiePreferences::fromRequest($request),
                'declared' => app(CookieRegistry::class)->grouped(),
            ],
        ];
    }

    /**
     * The operator-wide date and time conventions (#242).
     *
     * Resolved through Platform's own service, which finds the operator this
     * request runs as and falls back to the only row there is — so a brand
     * host, where no operator is in context, still renders the conventions of
     * the operator behind it.
     *
     * @return array{date: string, time: string}
     */
    private function dateTimeFormat(): array
    {
        $format = app(OperatorDateTimeFormat::class);

        return [
            'date' => $format->dateFormat()->value,
            'time' => $format->timeFormat()->value,
        ];
    }

    /**
     * The operator's brand/event pick for the console shell (slice 18):
     * the pick itself, the brands to pick from, and the events some module
     * offered for the picked brand.
     *
     * @return array{brandId: string|null, eventId: string|null, brands: list<array{id: string, name: string}>, events: list<array{id: string, name: string, brandId: string}>, eventsAvailable: bool}
     */
    private function consoleSelection(): array
    {
        $selection = app(ConsoleSelection::class);
        $sources = app(ConsoleEventSources::class);

        return [
            'brandId' => $selection->brandId(),
            'eventId' => $selection->eventId(),
            'brands' => array_values(
                Brand::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
                    ->all(),
            ),
            'events' => $sources->hasAny() ? $sources->eventsFor($selection->brandId()) : [],
            'eventsAvailable' => $sources->hasAny(),
        ];
    }

    /**
     * The footer entries of the operator behind the resolved brand: its
     * operator-wide rows plus this brand's own, operator-wide first and each
     * group in its own order.
     *
     * Empty off a brand host: the operator console renders no footer, and
     * asking for one there would be a query for markup nobody emits.
     *
     * @return array<int, FooterLinkData>
     */
    private function footerLinks(): array
    {
        $brand = app(BrandContext::class)->current();

        if ($brand === null || $brand->operator_id === null) {
            return [];
        }

        return FooterLink::query()
            ->where('operator_id', $brand->operator_id)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->getKey()))
            // Operator-wide rows first, then the brand's own, each in order.
            ->inDisplayOrder()
            ->get()
            ->map(fn (FooterLink $link): FooterLinkData => FooterLinkData::ofModel($link))
            ->all();
    }

    /**
     * The footer columns of the operator behind the resolved brand: its
     * operator-wide columns plus this brand's own, rendered through the
     * same block pipeline a static page uses.
     *
     * @return array<int, array{heading: string|null, blocks: list<array{key: string, component: string, props: array<string, mixed>}>}>
     */
    private function footerColumns(): array
    {
        $brand = app(BrandContext::class)->current();

        if ($brand === null || $brand->operator_id === null) {
            return [];
        }

        $renderer = app(BlockCompositionRenderer::class);

        return FooterColumn::query()
            ->where('operator_id', $brand->operator_id)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->getKey()))
            ->orderByRaw('brand_id IS NOT NULL')
            ->orderBy('position')
            ->get()
            ->map(fn (FooterColumn $column): array => [
                'heading' => $column->heading,
                'blocks' => $renderer->render($column->blocks?->toArray() ?? []),
            ])
            ->all();
    }

    /**
     * Whoever this request resolved to. Shared props are built for every
     * response, including ones rendered before ResolveBrand has run, so the
     * unresolved case has to be an answer rather than an exception.
     */
    private function identity(): BrandIdentityData
    {
        $resolver = app(BrandIdentityResolver::class);
        $brand = app(BrandContext::class)->current();

        if ($brand !== null) {
            return $resolver->forBrand($brand);
        }

        $operator = app(OperatorContext::class)->current();

        return $operator !== null
            ? $resolver->forOperator($operator)
            : $resolver->fallback();
    }
}
