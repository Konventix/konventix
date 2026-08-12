<?php

declare(strict_types=1);

namespace Konventix\Messaging\Enums;

/**
 * Messaging's capability catalogue (ADR-0013). Code, not data: role -> grant
 * mapping is per-brand data managed through spatie/laravel-permission.
 *
 * Seeing that a message was sent and reading its rendered body are separate
 * capabilities on purpose — the body usually carries personal data.
 */
enum Permission: string
{
    case ViewMessageLog = 'messaging.view-message-log';
    case ViewMessageContent = 'messaging.view-message-content';
    case ResendMessage = 'messaging.resend-message';
    case ManageMessageTemplates = 'messaging.manage-message-templates';
    case ManageSenderIdentity = 'messaging.manage-sender-identity';
    case ViewSuppressionList = 'messaging.view-suppression-list';
    case ManageSuppressionList = 'messaging.manage-suppression-list';
}
