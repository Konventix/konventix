<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * One registered GdprHandler module's response state for a given
 * DataSubjectRequest — see DataSubjectRequestResponse and
 * RecordModuleResponse.
 */
enum ModuleResponseState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
