<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * Whether a `RegistrationType`'s submissions need a reviewer's decision
 * before they can be confirmed (ADR-0005, SPEC.md "Owns" — `RegistrationType`).
 *
 * `ApproveRegistration`/`RejectRegistration` only operate on types where this
 * is `Required` — see `src/Actions/ApproveRegistration.php`.
 */
enum RegistrationApproval: string
{
    case None = 'none';
    case Required = 'required';
}
