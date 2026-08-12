<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * A PolicyVersion's publication state. Only PublishPolicyVersion transitions
 * a version into Published, and it supersedes whichever version of the same
 * policy was previously published — see that Action's docblock for why at
 * most one Published row can exist per policy at a time.
 */
enum PolicyVersionState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
