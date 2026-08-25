<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\ConsoleSelectionData;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: which brand and event the operator is looking at
 * (ROADMAP slice 18). No capability beyond `platform.access-admin`, which
 * the route group already requires — choosing what to look at is not an
 * operation on anything (ADR-0015).
 */
class ConsoleSelectionController
{
    public function update(
        ConsoleSelectionData $data,
        ConsoleSelection $selection,
        ConsoleEventSources $sources,
    ): RedirectResponse {
        $eventId = $data->eventId;

        if ($eventId !== null) {
            $known = collect($sources->eventsFor($data->brandId))->firstWhere('id', $eventId);

            // Unknown to every source, or known but somebody else's: a
            // source is free to ignore the brand it was handed, so the
            // brand the event names is what decides.
            if ($known === null || ($data->brandId !== null && $known['brandId'] !== $data->brandId)) {
                throw ValidationException::withMessages([
                    'eventId' => __('That event is not one of the selected brand\'s.'),
                ]);
            }
        }

        $selection->select($data->brandId, $eventId);

        return back();
    }
}
