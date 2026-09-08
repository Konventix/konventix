<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Registration\Actions\CreateRegistrationType;
use Konventix\Registration\Actions\UpdateRegistrationType;
use Konventix\Registration\Data\RegistrationTypeData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * Operator console: the offer itself (ADR-0005, ADR-0029). Until this screen
 * existed, the central object of the whole product was created by seeder or
 * tinker.
 *
 * Types are brand-owned and this is the admin host, where `BrandScope`
 * hardens to zero rows (ADR-0029) — so every query escapes it deliberately,
 * relation loads included (.ai/rules).
 *
 * The event picker reads Registration's own event read model, never Event's
 * table (ADR-0004).
 */
class RegistrationTypeAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageRegistrationTypes->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['name', 'settlement', 'approval', 'created_at'],
            default: 'name',
        );

        $types = RegistrationType::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->when($request->filled('event'), fn ($query) => $query->where('event_id', $request->string('event')->toString()))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $eventNames */
        $eventNames = EventSummary::withoutBrandScope()->pluck('name', 'event_id')->all();

        // withCount() re-applies BrandScope on the counted model, which reads
        // zero on this host — escape it per relation (.ai/rules).
        $registrationCounts = Registration::withoutBrandScope()
            ->selectRaw('registration_type_id, count(*) as aggregate')
            ->whereIn('registration_type_id', $types->getCollection()->pluck('id'))
            ->groupBy('registration_type_id')
            ->pluck('aggregate', 'registration_type_id');

        return Inertia::render('admin/registration-types/Index', [
            'types' => $types->through(fn (RegistrationType $type): array => [
                'id' => $type->getKey(),
                'name' => $type->name,
                'eventName' => $eventNames[$type->event_id] ?? $type->event_id,
                'visibility' => $type->visibility->value,
                'approval' => $type->approval->value,
                'settlement' => $type->settlement->value,
                'confirmationGate' => $type->confirmation_gate->value,
                'price' => $type->price_declaration?->unitAmountNet,
                'registrations' => (int) ($registrationCounts[$type->getKey()] ?? 0),
            ]),
            'brands' => $this->brandOptions(),
            'events' => $this->eventOptions(),
            'options' => $this->axisOptions(),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'event' => $request->string('event')->toString() ?: null,
            ],
        ]);
    }

    public function store(Request $request, CreateRegistrationType $createRegistrationType): RedirectResponse
    {
        Gate::authorize(Permission::ManageRegistrationTypes->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $type = $createRegistrationType->handle($brandId, RegistrationTypeData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration type created.')]);

        return to_route('admin.registration-types.edit', $type->getKey());
    }

    public function edit(string $registrationType): Response
    {
        Gate::authorize(Permission::ManageRegistrationTypes->value);

        $type = $this->find($registrationType);

        $registrations = Registration::withoutBrandScope()
            ->where('registration_type_id', $type->getKey())
            ->count();

        return Inertia::render('admin/registration-types/Edit', [
            'type' => [
                'id' => $type->getKey(),
                'brandName' => Brand::query()->whereKey($type->brand_id)->value('name') ?? '—',
                ...RegistrationTypeData::fromModel($type)->toArray(),
            ],
            'events' => $this->eventOptions($type->brand_id, include: $type->event_id),
            'quotas' => $this->quotaOptions($type->brand_id),
            'options' => $this->axisOptions(),
            'registrations' => $registrations,
            // #218 (fix round 1): the sales window is stored as an instant in
            // the application's own timezone, and a `datetime-local` control
            // labels itself as local time in every browser. The form names
            // both clocks rather than hard-coding one of them in prose.
            'appTimezone' => config('app.timezone'),
            // Terms freeze once anyone has registered under them — the form
            // disables what `UpdateRegistrationType` would refuse anyway.
            'termsFrozen' => $registrations > 0,
        ]);
    }

    public function update(Request $request, string $registrationType, UpdateRegistrationType $updateRegistrationType): RedirectResponse
    {
        Gate::authorize(Permission::ManageRegistrationTypes->value);

        $type = $this->find($registrationType);

        $updateRegistrationType->handle($type, RegistrationTypeData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration type saved.')]);

        return to_route('admin.registration-types.edit', $type->getKey());
    }

    private function find(string $registrationType): RegistrationType
    {
        /** @var RegistrationType */
        return RegistrationType::withoutBrandScope()
            ->with(['quotas' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->findOrFail($registrationType);
    }

    /**
     * Archived events are not offered — but one already referenced by the
     * type stays in the list, or editing that type's name would silently
     * move it to another event.
     *
     * @return list<array{id: string, name: string, brandId: string}>
     */
    private function eventOptions(?string $brandId = null, ?string $include = null): array
    {
        return array_values(EventSummary::withoutBrandScope()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('event_id', $include))
            ->orderBy('name')
            ->get()
            ->map(fn (EventSummary $summary): array => [
                'id' => $summary->event_id,
                'name' => $summary->name,
                'brandId' => $summary->brand_id,
            ])
            ->all());
    }

    /**
     * @return list<array{id: string, name: string, capacity: int, consumed: int}>
     */
    private function quotaOptions(string $brandId): array
    {
        return array_values(Quota::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->orderBy('name')
            ->get()
            ->map(fn (Quota $quota): array => [
                'id' => (string) $quota->getKey(),
                'name' => $quota->name,
                'capacity' => $quota->capacity,
                'consumed' => $quota->consumed,
            ])
            ->all());
    }

    /**
     * Every axis value the form may offer, from the enums themselves — so a
     * new case shows up in the console without anyone editing a page.
     *
     * @return array<string, list<string>>
     */
    private function axisOptions(): array
    {
        return [
            'visibility' => array_column(RegistrationVisibility::cases(), 'value'),
            'approval' => array_column(RegistrationApproval::cases(), 'value'),
            'settlement' => array_column(RegistrationSettlement::cases(), 'value'),
            'confirmationGate' => array_column(ConfirmationGate::cases(), 'value'),
            'grantKind' => array_column(GrantKind::cases(), 'value'),
            'taxClass' => array_column(TaxClass::cases(), 'value'),
            'priceDisplay' => array_column(PriceDisplay::cases(), 'value'),
        ];
    }
}
