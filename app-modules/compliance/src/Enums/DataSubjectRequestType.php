<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * The kind of data subject request (SPEC "Owns" — DataSubjectRequest).
 *
 * Export and Deletion each have a SPEC event (DataExportRequested,
 * UserDeletionRequested) that OpenSubjectRequest dispatches. Rectification
 * has no SPEC event yet — the request still opens, but nothing fans out
 * until SPEC defines one (see TODO.md follow-ups).
 */
enum DataSubjectRequestType: string
{
    case Export = 'export';
    case Deletion = 'deletion';
    case Rectification = 'rectification';
}
