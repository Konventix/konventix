<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * What a single entry in a `RegistrationType`'s `grants` collection produces
 * once a registration confirms (ADR-0005, SPEC.md "Grant definitions").
 *
 * `Admission` grants are what `Konventix\Admission`'s listener reacts to
 * (one `Admission` row per unit of `quantity`). `ResourceClaim` grants are a
 * placeholder for Resources' future booking claim — Phase 1 never emits one,
 * but the axis exists so `RegistrationType::grants` can already describe it.
 */
enum GrantKind: string
{
    case Admission = 'admission';
    case ResourceClaim = 'resource_claim';
}
