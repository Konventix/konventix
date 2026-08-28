<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Messaging\Actions\ResetTemplateOverride;
use Konventix\Messaging\Actions\SaveTemplateOverride;
use Konventix\Messaging\Branding\BrandMailIdentityResolver;
use Konventix\Messaging\Data\SaveTemplateOverrideData;
use Konventix\Messaging\Data\TemplateEditData;
use Konventix\Messaging\Data\TemplatePreviewData;
use Konventix\Messaging\Data\TemplateSummaryData;
use Konventix\Messaging\Enums\Permission;
use Konventix\Messaging\Exceptions\UnsafeTemplateException;
use Konventix\Messaging\Mail\MailLayout;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateOverrideGuard;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;
use Throwable;

/**
 * Operator console: the template editor (ADR-0048 decisions 4 and 6) —
 * Messaging owns the look, but an operator can rewrite a mail per brand,
 * preview it, and send themselves a test. Route middleware already requires
 * the admin host, auth and `platform.access-admin`; every action here
 * additionally requires `messaging.manage-message-templates`.
 *
 * A brand-owned model (`TemplateOverride`) queried from the admin host,
 * where `BrandScope` hardens to zero rows (ADR-0029) — every query here
 * escapes it and stamps the brand explicitly. The brand every write acts on
 * is always `ConsoleSelection`'s (session, server-side) — never a `brandId`
 * the client submits — the same way `edit()`/`reset()` already worked: an
 * operator's own request body deciding which brand's row gets touched would
 * let any holder of `manage-message-templates` (a brand-operator capability,
 * not a superuser one) write into a brand they were never handed the console
 * selection for.
 */
class TemplateAdminController
{
    use HasDataTableQuery;

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TemplateRenderer $renderer,
        private readonly TemplateOverrideGuard $guard,
        private readonly BrandMailIdentityResolver $identity,
    ) {}

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ManageMessageTemplates->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['key', 'module'], default: 'key');
        $brandId = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        $overriddenByKey = $brandId === null
            ? collect()
            : TemplateOverride::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->get()
                ->groupBy('template_key');

        $all = collect($this->registry->all())
            ->map(fn (MessageTemplate $template): array => TemplateSummaryData::ofTemplate(
                $template,
                $overriddenByKey->get($template->key, collect())->pluck('locale')->values()->all(),
            )->toArray())
            ->when($request->filled('search'), function (Collection $templates) use ($request): Collection {
                $term = mb_strtolower($request->string('search')->trim()->toString());

                return $templates->filter(fn (array $template): bool => str_contains(mb_strtolower($template['key']), $term)
                    || str_contains(mb_strtolower($template['module']), $term));
            })
            ->sortBy($sort['column'], SORT_REGULAR, $sort['direction'] === 'desc')
            ->values();

        // The registry is an in-memory catalogue, not a query — paginated
        // by hand the same shape `Builder::paginate()` produces, so the
        // page follows the same `DataTable`/`useDataTable` contract every
        // other operator-console index does.
        $page = LengthAwarePaginator::resolveCurrentPage();
        $templates = new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return Inertia::render('admin/messages/templates/Index', [
            'templates' => $templates,
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brandId]),
        ]);
    }

    public function edit(string $key, ConsoleSelection $selection): Response|RedirectResponse
    {
        Gate::authorize(Permission::ManageMessageTemplates->value);

        $brandId = $selection->brandId();

        if ($brandId === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Pick a brand first.')]);

            return to_route('admin.messages.templates.index');
        }

        if (! $this->registry->has($key)) {
            abort(404);
        }

        $template = $this->registry->get($key);
        $brand = Brand::query()->findOrFail($brandId);

        $overridesByLocale = TemplateOverride::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('template_key', $key)
            ->get()
            ->keyBy('locale');

        $locales = array_keys($template->subjects);
        $defaults = [];
        $overrides = [];

        foreach ($locales as $locale) {
            $defaults[$locale] = [
                'subject' => $template->subjectFor($locale),
                'body' => $template->bodyFor($locale),
            ];

            /** @var TemplateOverride|null $override */
            $override = $overridesByLocale->get($locale);

            // Either field on the row may be null (falls back to the
            // default), so the value the editor starts from is always the
            // effective one — the same fallback TemplateRenderer applies.
            $overrides[$locale] = $override === null ? null : [
                'subject' => $override->subject ?? $template->subjectFor($locale),
                'body' => $override->body ?? $template->bodyFor($locale),
            ];
        }

        return Inertia::render('admin/messages/templates/Edit', [
            'template' => new TemplateEditData(
                key: $template->key,
                module: $this->moduleOf($template),
                locales: $locales,
                defaultLocale: $template->defaultLocale,
                defaults: $defaults,
                overrides: $overrides,
                sample: $template->sample,
                brandId: (string) $brand->getKey(),
                brandName: $brand->name,
            )->toArray(),
        ]);
    }

    public function update(string $key, SaveTemplateOverrideData $data, ConsoleSelection $selection, SaveTemplateOverride $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageMessageTemplates->value);

        $brandId = $selection->brandId();

        if ($brandId === null) {
            throw ValidationException::withMessages(['body' => __('Pick a brand first.')]);
        }

        if (! $this->registry->has($key)) {
            abort(404);
        }

        $template = $this->registry->get($key);

        if (! $template->supportsLocale($data->locale)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This template has no such locale.')]);

            return to_route('admin.messages.templates.edit', $key);
        }

        $brand = Brand::query()->findOrFail($brandId);

        $action->handle($brand, $key, $data->locale, $data->subject, $data->body);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Override saved.')]);

        return to_route('admin.messages.templates.edit', $key);
    }

    public function reset(string $key, string $locale, ConsoleSelection $selection, ResetTemplateOverride $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageMessageTemplates->value);

        $brandId = $selection->brandId();

        if ($brandId === null) {
            abort(404);
        }

        $brand = Brand::query()->findOrFail($brandId);

        $action->handle($brand, $key, $locale);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Override reset to default.')]);

        return to_route('admin.messages.templates.edit', $key);
    }

    /**
     * An XHR from the editor's live-preview pane — JSON is the right shape
     * here, not a redirect-with-session-errors Inertia response. Executes a
     * draft that was never saved, so the allow-list guard runs here too,
     * not just on save.
     */
    public function preview(string $key, TemplatePreviewData $data, ConsoleSelection $selection): JsonResponse
    {
        Gate::authorize(Permission::ManageMessageTemplates->value);

        $brandId = $selection->brandId();

        if ($brandId === null) {
            return response()->json(['message' => __('Pick a brand first.')], 422);
        }

        if (! $this->registry->has($key)) {
            return response()->json(['message' => __('Unknown template.')], 404);
        }

        $template = $this->registry->get($key);

        try {
            $this->guard->assertSafe($data->subject);
            $this->guard->assertSafe($data->body);
        } catch (UnsafeTemplateException $exception) {
            return response()->json(['message' => $exception->userMessage()], 422);
        }

        try {
            $rendered = $this->renderer->renderString($data->subject, $data->body, $data->locale, $template->sample);
        } catch (Throwable $exception) {
            return response()->json(['message' => "The template does not compile: {$exception->getMessage()}"], 422);
        }

        $wrapped = MailLayout::wrap($rendered, $this->identity->for($brandId), preferencesUrl: null);

        return response()->json(['subject' => $rendered->subject, 'html' => $wrapped['html']]);
    }

    /**
     * The prefix before the key's first dot — its owning module, by
     * convention every registered key follows (`commerce.invoice-issued`
     * → `commerce`).
     */
    private function moduleOf(MessageTemplate $template): string
    {
        $module = strstr($template->key, '.', true);

        return $module === false ? $template->key : $module;
    }
}
