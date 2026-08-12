<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * Who may reach a `RegistrationType`'s form at all (ADR-0005, SPEC.md
 * "Owns" — `RegistrationType`).
 *
 * Phase 1 only builds the `Public` path end to end. `Link` and `InviteOnly`
 * both require the invitation/link token system, which TODO.md carries as a
 * Phase 2 backlog item — until then the public routes reject both with a
 * 403, which is the behaviour under test.
 */
enum RegistrationVisibility: string
{
    case Public = 'public';
    case Link = 'link';
    case InviteOnly = 'invite_only';
}
