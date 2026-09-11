<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Data\MyTicketData;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;

/**
 * The holder's own tickets (design §4.1, §7): every admission whose
 * `holder_user_id` is the signed-in account, whichever registration — or
 * none, for a staff admission (D-STF-01) — produced it.
 */
final class MyTicketsController
{
    public function index(Request $request): Response
    {
        $admissions = Admission::query()
            ->where('holder_user_id', (string) $request->user()->getAuthIdentifier())
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderByDesc('created_at')
            ->get();

        $eventNames = EventSummary::query()
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->pluck('name', 'event_id');

        return Inertia::render('admission/tickets/Index', [
            'tickets' => $admissions
                ->map(fn (Admission $admission): array => MyTicketData::ofModel(
                    $admission,
                    $eventNames->get($admission->event_id),
                )->toArray())
                ->all(),
        ]);
    }
}
