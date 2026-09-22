<?php

declare(strict_types=1);

namespace Konventix\Community\Enums;

/**
 * Where a `MessageReport` stands in the desk's queue (design §4.4). The
 * desk resolves or dismisses an open report; a classifier writing reports
 * later (design §9, D-COM-02) lands here the same way a human reporter
 * does.
 */
enum ReportState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
