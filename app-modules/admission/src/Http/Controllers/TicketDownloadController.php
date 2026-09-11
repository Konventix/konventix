<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Konventix\Admission\Documents\TicketDocumentRenderer;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The buyer's whole file (design §4.1, §7). Authorised entirely on
 * Admission's own columns — never by reading a Registration row
 * (ADR-0004), the same reasoning that keeps Resources' seat picker off
 * Registration's tables. Stores nothing:
 * `TicketDocumentRenderer::render()` returns bytes, mirroring the badge
 * (§4.4).
 */
final class TicketDownloadController
{
    /**
     * One page per admission the registration granted, in the order they
     * were issued, a revoked one never among them. `{registration}` stays
     * a plain ULID rather than a route-model binding — Admission may not
     * import Registration's model (ADR-0004) — so ownership is decided
     * entirely by whether at least one admission this registration granted
     * names the signed-in account as `buyer_user_id`; a stranger and a
     * registration id this module has never heard of answer the same 403,
     * the information shape `MyRegistrationsController::show`'s inline
     * ownership check already keeps.
     */
    public function buyer(Request $request, string $registration, TicketDocumentRenderer $renderer): SymfonyResponse
    {
        abort_unless(
            Admission::query()
                ->where('registration_id', $registration)
                ->where('buyer_user_id', (string) $request->user()->getAuthIdentifier())
                ->exists(),
            403,
        );

        $admissions = Admission::query()
            ->where('registration_id', $registration)
            ->where('buyer_user_id', (string) $request->user()->getAuthIdentifier())
            ->where('state', '!=', AdmissionState::Revoked)
            ->orderBy('created_at')
            ->get();

        // Every admission this buyer owns on this registration has since
        // been revoked: nothing left to serve, and a "download" opening to
        // a blank document would be worse than a 404.
        abort_if($admissions->isEmpty(), 404);

        $bytes = $renderer->render($admissions);

        $slug = EventSummary::query()->where('event_id', $admissions->first()->event_id)->value('slug')
            ?? $admissions->first()->event_id;

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="tickets-%s.pdf"', $slug),
        ]);
    }

    /**
     * The counterpart to `buyer()` above, and the only route that can ever
     * serve a staff admission's ticket: `registration_id` is null for one
     * (D-STF-01), so `buyer()` has nothing to key on for it.
     */
    public function holder(Request $request, Admission $admission, TicketDocumentRenderer $renderer): SymfonyResponse
    {
        abort_unless($admission->holder_user_id === (string) $request->user()->getAuthIdentifier(), 403);
        abort_if($admission->state === AdmissionState::Revoked, 404, 'This ticket has been revoked.');

        $bytes = $renderer->render(collect([$admission]));

        $slug = EventSummary::query()->where('event_id', $admission->event_id)->value('slug')
            ?? $admission->event_id;

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="ticket-%s.pdf"', $slug),
        ]);
    }
}
