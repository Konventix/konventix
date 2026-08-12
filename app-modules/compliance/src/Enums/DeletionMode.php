<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * How a GdprHandler must discharge a deletion request (SPEC
 * UserDeletionRequested payload: `mode`).
 */
enum DeletionMode: string
{
    case Anonymize = 'anonymize';
    case Erase = 'erase';
}
