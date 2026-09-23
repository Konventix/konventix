<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * What a checkpoint is for (attendee-list design §3.1, §6).
 *
 * `Door` is a place devices are enrolled at and scan tokens. `Console` is the
 * one checkpoint per event the operator console writes its check-ins against:
 * it exists so a console check-in has a checkpoint like every other row, and
 * it is never offered to a device (ADR-0033 as amended) — every enrolment
 * surface reads `Checkpoint::doors()` (#328).
 */
enum CheckpointKind: string
{
    case Door = 'door';
    case Console = 'console';
}
