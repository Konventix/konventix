<?php

declare(strict_types=1);

namespace Konventix\Platform\Permissions;

/**
 * What one materialization run did for one brand (ADR-0045), by role name —
 * the command prints it so a deploy log says what changed and, more
 * importantly, what was deliberately left alone.
 */
final class MaterializationReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $skippedCustomized = [];

    /** @var list<string> */
    public array $skippedCollision = [];

    public function summary(): string
    {
        return sprintf(
            '%d created, %d updated, %d customized (left alone), %d name collisions (skipped)',
            count($this->created),
            count($this->updated),
            count($this->skippedCustomized),
            count($this->skippedCollision),
        );
    }
}
