<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Actions\SaveEventHubLayout;
use Konventix\Event\Data\UpdateHubLayoutData;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventHubLayout;
use Konventix\Platform\Hub\HubCatalogue;

/**
 * Operator console: the live hub's composition for one event (design §6,
 * ADR-0029). The page lists every card and link Platform's registry knows,
 * read through Platform's `HubCatalogue` — Event calls the host module's
 * public service, never the reverse (ADR-0004), the same way it registers
 * navigation. The layout is handed to the page exactly as stored; the page
 * seeds its form by appending catalogue entries the layout has not met
 * yet, and Platform does the same merge at render time.
 *
 * Every query escapes BrandScope by id: the admin host hardens the scope
 * to nothing (ADR-0029).
 */
class EventHubLayoutController
{
    public function edit(string $event, HubCatalogue $catalogue): Response
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $model = $this->find($event);
        $layout = EventHubLayout::withoutBrandScope()->where('event_id', $model->id)->first();

        return Inertia::render('admin/events/Hub', [
            'event' => ['id' => $model->id, 'name' => $model->name],
            'catalogue' => $catalogue->entries(),
            'layout' => $layout === null
                ? ['cards' => [], 'hubReplacesHome' => false]
                : ['cards' => $layout->cards, 'hubReplacesHome' => $layout->hub_replaces_home],
        ]);
    }

    public function update(Request $request, string $event, SaveEventHubLayout $save): RedirectResponse
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $model = $this->find($event);
        $save->handle($model, UpdateHubLayoutData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Live hub saved.')]);

        return to_route('admin.events.hub.edit', $model->id);
    }

    private function find(string $event): Event
    {
        /** @var Event */
        return Event::withoutBrandScope()->findOrFail($event);
    }
}
