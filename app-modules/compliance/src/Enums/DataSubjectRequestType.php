<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * The kind of data subject request (SPEC "Owns" — DataSubjectRequest).
 *
 * Export and Deletion each have a SPEC event (DataExportRequested,
 * UserDeletionRequested) that OpenSubjectRequest dispatches. Rectification
 * has no SPEC event yet — the request still opens, but nothing fans out
 * until SPEC defines one (see TODO.md follow-ups). ConsentWithdrawal fans
 * out to nothing at all — it is Compliance answering about its own Consent
 * record, not a cross-module obligation (ADR-0050 §8).
 */
enum DataSubjectRequestType: string
{
    case Export = 'export';
    case Deletion = 'deletion';
    case Rectification = 'rectification';

    // Named "consent" explicitly, not "policy withdrawal" — SPEC already
    // uses `PolicyWithdrawn` for a *policy* being retired, and two meanings
    // of "withdrawal" one enum apart is how the wrong one gets handled
    // (ADR-0050 §8).
    case ConsentWithdrawal = 'consent_withdrawal';
}
