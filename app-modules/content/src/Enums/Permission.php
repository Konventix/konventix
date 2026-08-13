<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * Content's capability catalogue (ADR-0013).
 *
 * Moderating comments is separate from publishing entries because they are
 * different jobs: a community moderator should be able to hide an abusive
 * comment without also being able to publish the front page.
 */
enum Permission: string
{
    case ViewEntries = 'content.view-entries';
    case ManageEntries = 'content.manage-entries';
    case PublishEntries = 'content.publish-entries';
    case ModerateComments = 'content.moderate-comments';
}
