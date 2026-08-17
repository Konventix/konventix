<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Enums\RetentionClockStart;
use Konventix\Compliance\Models\RetentionClock;
use Konventix\Compliance\Models\RetentionRule;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: how long each category of data may be kept (ADR-0029).
 *
 * The screen shows what the sweep will do, not what it did — including the
 * cutoff each rule currently implies, because "36 months" and "everything
 * before 2023-08-17" are the same fact and only one of them is checkable at a
 * glance.
 *
 * Rules are administered here and nowhere else: a category with two rules
 * would be two answers to one legal question, which the unique index refuses
 * at the database as well.
 */
class RetentionRuleController
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageRetentionRules->value);

        /** @var array<string, string> $brandNames */
        $brandNames = Brand::query()->pluck('name', 'id')->all();

        return Inertia::render('admin/retention/Index', [
            'rules' => RetentionRule::query()
                ->orderBy('data_category')
                ->get()
                ->map(fn (RetentionRule $rule): array => [
                    'id' => $rule->getKey(),
                    'dataCategory' => $rule->data_category,
                    'retentionMonths' => $rule->retention_months,
                    'clockStart' => $rule->clock_start->value,
                    'description' => $rule->description,
                    'active' => $rule->active,
                    // Null brand is the operator-wide rule, not a missing one.
                    'brandName' => $rule->brand_id === null
                        ? null
                        : ($brandNames[$rule->brand_id] ?? $rule->brand_id),
                    'brandId' => $rule->brand_id,
                    // What the next sweep would use. Only meaningful for
                    // record-age rules; an event-scoped one has a cutoff per
                    // event, shown as the clock count instead.
                    'currentCutoff' => $rule->clock_start === RetentionClockStart::RecordAge
                        ? now()->subMonths($rule->retention_months)->toDateString()
                        : null,
                ])->all(),
            'brands' => array_values(Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
                ->all()),
            'clockStarts' => array_column(RetentionClockStart::cases(), 'value'),
            // How many events have concluded and are therefore counting.
            'clocks' => RetentionClock::query()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageRetentionRules->value);

        $validated = $this->validated($request);

        RetentionRule::query()->create($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retention rule created.')]);

        return back();
    }

    public function update(Request $request, string $rule): RedirectResponse
    {
        Gate::authorize(Permission::ManageRetentionRules->value);

        /** @var RetentionRule $model */
        $model = RetentionRule::query()->findOrFail($rule);

        $model->forceFill($this->validated($request))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retention rule saved.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'brand_id' => ['nullable', 'ulid', 'exists:brands,id'],
            'data_category' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(\-[a-z0-9]+)*(\.[a-z0-9]+(\-[a-z0-9]+)*)+$/'],
            // A zero-month rule would delete on the next sweep, which is
            // never what someone means by "set a retention period".
            'retention_months' => ['required', 'integer', 'min:1', 'max:600'],
            'clock_start' => ['required', 'in:record_age,event_concluded'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);

        return $validated;
    }
}
