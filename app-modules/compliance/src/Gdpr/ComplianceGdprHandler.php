<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * Compliance's contribution about its own rows — the module that orchestrates
 * the fan-out taking part in it like everyone else.
 *
 * Export matters here: what a person agreed to, when, and which requests they
 * filed is exactly the record Art. 15 entitles them to see, and it is the
 * record this module is otherwise asking every other module to produce.
 *
 * **Deletion deliberately does nothing.** A consent record is the evidence
 * that consent was given (Art. 7(1) requires the controller to be able to
 * demonstrate it) and a subject request is the evidence that the request was
 * handled within its deadline. Deleting either to satisfy a deletion request
 * would destroy the proof that the deletion request was honoured — the one
 * self-defeating outcome in the whole fan-out. Neither row carries a name or
 * an address; the subject is a ULID pointing at Identity's tombstone.
 */
final class ComplianceGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'compliance';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        return [
            'consents' => Consent::withoutBrandScope()
                ->where('subject_id', $subjectId)
                ->with('policyVersion.policy')
                ->orderBy('granted_at')
                ->get()
                ->map(fn (Consent $consent): array => [
                    'policy' => $consent->policyVersion->policy->slug,
                    'version' => $consent->policyVersion->version,
                    'granted_at' => $consent->granted_at->toIso8601String(),
                    'withdrawn_at' => $consent->withdrawn_at?->toIso8601String(),
                    'capture_context' => $consent->capture_context,
                ])
                ->all(),
            'subject_requests' => DataSubjectRequest::withoutBrandScope()
                ->where('subject_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DataSubjectRequest $request): array => [
                    'type' => $request->type->value,
                    'state' => $request->state->value,
                    'opened_at' => $request->created_at?->toIso8601String(),
                    'deadline_at' => $request->deadline_at->toIso8601String(),
                    'closed_at' => $request->closed_at?->toIso8601String(),
                    'reason_code' => $request->reason_code,
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        // Intentionally empty — see the class docblock.
    }
}
