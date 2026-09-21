<?php

declare(strict_types=1);

namespace Konventix\Messaging\Topics;

use Konventix\Messaging\Enums\TopicAudience;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Enums\Permission as PlatformPermission;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Permissions\PermissionHolders;

/**
 * The topics one account is offered on the preferences page (bugfix round
 * 2026-09-21, Task 9): every participant topic, plus the operator topics
 * for an account that can actually reach the console — one holding
 * `platform.access-admin` on the operator's own team. Asking a registrant
 * whether they want "Shop operations" notices they can never receive is a
 * settings page describing somebody else's job.
 *
 * The operator is whichever one the request resolved: the brand's on a
 * brand host, the console's on the admin host (ADR-0029). Both contexts
 * are scoped bindings, resolved at call time (`.ai/rules/providers.md`).
 */
final class VisibleTopics
{
    public function __construct(
        private readonly TopicRegistry $topics,
        private readonly PermissionHolders $permissionHolders,
    ) {}

    /**
     * @return list<Topic>
     */
    public function for(string $recipientId): array
    {
        $operatorTopicsVisible = $this->reachesTheConsole($recipientId);

        return array_values(array_filter(
            $this->topics->all(),
            fn (Topic $topic): bool => $topic->audience === TopicAudience::Participant || $operatorTopicsVisible,
        ));
    }

    private function reachesTheConsole(string $recipientId): bool
    {
        $operatorId = app(OperatorContext::class)->id()
            ?? app(BrandContext::class)->current()?->operator_id;

        if ($operatorId === null) {
            return false;
        }

        return in_array(
            $recipientId,
            $this->permissionHolders->userIdsWithPermission($operatorId, PlatformPermission::AccessAdmin->value),
            true,
        );
    }
}
