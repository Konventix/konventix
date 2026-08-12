<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * DataSubjectRequest workflow state (SPEC "Owns" — the data subject request
 * workflow). OpenSubjectRequest only ever creates Received rows; the
 * transitions into InProgress/Fulfilled/Refused are backlog (export
 * assembly, audit trail) and not yet implemented by any Action.
 */
enum DataSubjectRequestState: string
{
    case Received = 'received';
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Refused = 'refused';
}
