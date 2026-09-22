<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Whether a reported result or a score entry carries a proof image
 * (D-CMP-19, design §8.1). The image itself lives in Platform's media
 * library (ADR-0035) once §8's slice lands.
 */
enum ProofPolicy: string
{
    case None = 'none';
    case Optional = 'optional';
    case Required = 'required';
}
