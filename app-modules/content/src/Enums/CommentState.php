<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * A comment's moderation state.
 *
 * `Pending` exists so a brand can pre-moderate; brands that do not simply
 * start comments `Approved`. `Hidden` is distinct from `Rejected`: rejecting
 * turns down a submission, hiding removes something that was already public,
 * and a moderator needs to know which happened.
 */
enum CommentState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
}
