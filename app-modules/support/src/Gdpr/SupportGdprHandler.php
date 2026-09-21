<?php

declare(strict_types=1);

namespace Konventix\Support\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * Support's contribution to a subject request (design §10.5, D-SUP-08).
 *
 * **It answers for an e-mail-only subject, which most handlers cannot.**
 * A guest report is personal data held about somebody we never gave an
 * account, and the address is the only handle there is — so an
 * address-backed `SubjectRef` finds reports here where it finds nothing in
 * most other modules (ADR-0050 §3). An account-backed subject is matched on
 * `user_id` alone: the address that would also match lives in Identity, and
 * this module may not read another module's model (ADR-0004). Compliance
 * dispatches the fan-out per subject, so a person who wants both halves is
 * one whose account and address are both known to Compliance.
 *
 * **No `withoutBrandScope()` anywhere, on purpose.** The rule for handlers
 * is "query with `withoutBrandScope()` and no brand filter" because a
 * subject is a person, not a person-at-a-brand — and Support's tables are
 * already unscoped: `filed_on_brand_id` is provenance, not a scope
 * ({@see SupportRequest}). A subject's reports therefore come back across
 * every brand and the console, with no escape hatch needed.
 *
 * **Deletion keeps the row.** Both modes behave identically and neither
 * deletes: the report is the operator's record that something was broken on
 * a given release at a given URL, and that record is not about the person.
 * What is about the person goes — the account id, the address, what they
 * typed here and in their own messages, the user agent, the viewport, the
 * visit buffer and the captured client error. What survives is an incident
 * with a correlation id and no reporter. Retention takes the rest of it
 * after the window (`support:purge-requests`).
 *
 * Both methods are idempotent: a second run finds the fields already null
 * and the bodies already replaced, and changes nothing.
 */
final class SupportGdprHandler implements GdprHandler
{
    /**
     * What a scrubbed text says. A fixed sentence rather than an empty
     * string, so a console reading the row later can tell "erased on
     * request" from "somebody sent an empty report".
     */
    public const string REMOVED = '[removed on request]';

    public function module(): string
    {
        return 'support';
    }

    public function exportFor(SubjectRef $subject): array
    {
        $requests = $this->requestsFor($subject);

        if ($requests === []) {
            return [];
        }

        return [
            'support_requests' => array_map(
                fn (SupportRequest $request): array => [
                    'reference' => $request->reference(),
                    'filed_on_brand_id' => $request->filed_on_brand_id,
                    'categories' => $request->categories,
                    'message' => $request->message,
                    'state' => $request->state->value,
                    'contact_consent' => $request->contact_consent,
                    'external_reference' => $request->external_reference,
                    // Minus the user agent: a browser fingerprint answers a
                    // question nobody asked (design §10.5).
                    'context' => $request->context->toExportArray(),
                    'created_at' => $request->created_at?->toIso8601String(),
                    'seen_at' => $request->seen_at?->toIso8601String(),
                    'closed_at' => $request->closed_at?->toIso8601String(),
                    // The person's own messages only. A staff note is the
                    // operator's internal handling, never the reporter's
                    // data, and an external reply is ours to have written.
                    'my_messages' => $this->ownMessagesOf($request, $subject),
                ],
                $requests,
            ),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        $requests = $this->requestsFor($subject);

        if ($requests === []) {
            return;
        }

        DB::transaction(function () use ($requests, $subject): void {
            foreach ($requests as $request) {
                // The author id is what identifies the person's own
                // messages, so they are scrubbed before it is nulled.
                $this->messageQueryFor($request, $subject)->update(['body' => self::REMOVED]);

                $request->forceFill([
                    'user_id' => null,
                    'email' => null,
                    'message' => self::REMOVED,
                    'context' => $request->context->withoutPersonalData(),
                ])->save();
            }
        });
    }

    /**
     * Every report this subject filed, by whichever handle they have.
     *
     * @return list<SupportRequest>
     */
    private function requestsFor(SubjectRef $subject): array
    {
        $query = SupportRequest::query()->orderBy('created_at');

        if ($subject->isAccountBacked()) {
            $query->where('user_id', $subject->userId);
        } else {
            // Stored lower-cased; SubjectRef normalizes the same way.
            $query->where('email', $subject->email);
        }

        return array_values($query->get()->all());
    }

    /**
     * The messages in this thread the subject wrote themselves.
     *
     * A guest reporter has no author id — they are identified by the signed
     * link and nothing else (design §4.2) — so for an address-backed
     * subject "their messages" are the thread's author-less external ones.
     * That is exact as long as the request itself is theirs, which
     * {@see requestsFor()} has already established.
     *
     * @return Builder<SupportRequestMessage>
     */
    private function messageQueryFor(SupportRequest $request, SubjectRef $subject): Builder
    {
        $query = SupportRequestMessage::query()->where('support_request_id', $request->getKey());

        return $subject->isAccountBacked()
            ? $query->where('author_user_id', $subject->userId)
            : $query->whereNull('author_user_id')->visibleToReporter();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownMessagesOf(SupportRequest $request, SubjectRef $subject): array
    {
        return array_values($this->messageQueryFor($request, $subject)
            ->orderBy('created_at')
            ->get()
            ->map(fn (SupportRequestMessage $message): array => [
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
