<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners;

use Konventix\Messaging\Actions\SendMessage;
use Konventix\Support\Data\SupportStateChangedData;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Listeners\Concerns\ReachesTheReporter;
use Konventix\Support\Models\SupportRequest;

/**
 * "Somebody is looking at your report", "it is fixed", "we will not fix
 * this" — the reporter's half of `SupportRequestStateChanged` (design §7).
 *
 * Three of the five states are worth a mail, and `seen` is deliberately not
 * one of them. `received → seen` fires the moment the first staff member
 * opens the inbox entry, which can be seconds after the receipt landed;
 * mailing that would teach every reporter that this module's mails are
 * noise, and the receipt already told them how to check for themselves.
 * `received` is never moved *into*, so the list is the three a human
 * chooses.
 *
 * Consent governs it — {@see ReachesTheReporter} holds that gate, and it is
 * the promise made beside the checkbox: without it, the receipt was the
 * last thing we send.
 *
 * **Idempotent** in the only sense available to something that sends mail:
 * it writes nothing, reads the row fresh, and a second delivery of the same
 * event produces the same message rather than a different one or a changed
 * report. Messaging's own delivery log is what stops a retried *job* from
 * being a second mail.
 */
final class NotifyReporterOnStateChanged
{
    use ReachesTheReporter;

    public function __construct(private readonly SupportLinks $links) {}

    public function handle(SupportRequestStateChanged $event): void
    {
        $to = SupportRequestState::tryFrom($event->to);

        if ($to === null || ! in_array($to, self::worthTelling(), true)) {
            return;
        }

        $request = SupportRequest::query()->whereKey($event->requestId)->first();

        if ($request === null) {
            return;
        }

        // Scoped bindings are resolved here and never in the constructor:
        // the dispatcher builds listeners with the worker's base container
        // (`.ai/rules/listeners.md`). `SendMessage` is a stateless Action,
        // so it may be injected; `SupportLinks` reads Platform's brand and
        // operator settings per call.
        $this->tellTheReporter(
            sendMessage: app(SendMessage::class),
            request: $request,
            templateKey: 'support.request-state-changed',
            payload: new SupportStateChangedData(
                reference: $request->reference(),
                stateDe: self::label($to, 'de'),
                stateEn: self::label($to, 'en'),
                noteDe: self::note($to, 'de'),
                noteEn: self::note($to, 'en'),
                requestUrl: $this->links->reporterUrl($request),
            ),
        );
    }

    /**
     * @return list<SupportRequestState>
     */
    private static function worthTelling(): array
    {
        return [
            SupportRequestState::InProgress,
            SupportRequestState::Resolved,
            SupportRequestState::WontFix,
        ];
    }

    /**
     * The state's name in one of the mail's two bodies. The template is a
     * `de`/`en` map with a prepared field per locale, so each half is looked
     * up in the catalog for its own locale explicitly — the request that
     * moved the state is the operator's, and its locale says nothing about
     * the reporter's (ADR-0068).
     */
    private static function label(SupportRequestState $state, string $locale): string
    {
        return match ($state) {
            SupportRequestState::InProgress => __('In progress', [], $locale),
            SupportRequestState::Resolved => __('Resolved', [], $locale),
            SupportRequestState::WontFix => __("Won't fix", [], $locale),
            default => $state->label(),
        };
    }

    private static function note(SupportRequestState $state, string $locale): string
    {
        return match ($state) {
            SupportRequestState::InProgress => __('Somebody is working on it now.', [], $locale),
            SupportRequestState::Resolved => __('We have fixed it. If it still happens, just answer on your report.', [], $locale),
            SupportRequestState::WontFix => __('We are going to leave this as it is. The reasoning is on your report.', [], $locale),
            default => '',
        };
    }
}
