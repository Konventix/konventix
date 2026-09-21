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
use Konventix\Admission\Models\Person;

/**
 * The holder's own tickets (design §4.1, §7): every admission whose
 * `holder_user_id` is the signed-in account, whichever registration — or
 * none, for a staff admission (D-STF-01) — produced it. Events are named
 * and linked from this module's own summary; holders are handles
 * (ADR-0065).
 */
final class MyTicketsController
{
    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();

        $admissions = Admission::query()
            ->where('holder_user_id', $userId)
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderByDesc('created_at')
            ->get();

        // The tickets this person bought but does not hold (#292 §4.5):
        // a manager who holds none of their group's tickets still needs a
        // way in, and this is it.
        $managed = Admission::query()
            ->where('buyer_user_id', $userId)
            ->where(fn ($query) => $query->whereNull('holder_user_id')->orWhere('holder_user_id', '!=', $userId))
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderByDesc('created_at')
            ->get();

        $events = EventSummary::query()
            ->whereIn('event_id', $admissions->pluck('event_id')->merge($managed->pluck('event_id'))->unique())
            ->get()
            ->keyBy('event_id');

        $holderUsernames = Person::query()
            ->whereIn('id', $managed->pluck('holder_user_id')->filter()->unique())
            ->pluck('username', 'id');

        return Inertia::render('admission/tickets/Index', [
            'tickets' => $admissions
                ->map(fn (Admission $admission): array => MyTicketData::ofModel(
                    $admission,
                    $events->get($admission->event_id)?->name,
                    $events->get($admission->event_id)?->slug,
                )->toArray())
                ->all(),
            'managed' => $managed
                ->map(fn (Admission $admission): array => MyTicketData::ofModel(
                    $admission,
                    $events->get($admission->event_id)?->name,
                    $events->get($admission->event_id)?->slug,
                    $admission->holder_user_id === null ? null : $holderUsernames->get($admission->holder_user_id),
                )->toArray())
                ->all(),
        ]);
    }
}
