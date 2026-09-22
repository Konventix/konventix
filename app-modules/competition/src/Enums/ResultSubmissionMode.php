<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Who may report a result (D-CMP-19, design §8.1). The desk can always
 * record, whatever this says.
 */
enum ResultSubmissionMode: string
{
    case DeskOnly = 'desk_only';
    case Participants = 'participants';
}
