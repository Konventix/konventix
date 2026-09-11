<?php

declare(strict_types=1);

namespace Konventix\Resources\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Resources\Data\PlanConflictData;
use RuntimeException;

/**
 * The save would delete, recategorise or block occupied places and at least
 * one of them has no resolution (design §6.1 step 4). Carries the complete
 * list, resolved ones included, so the dialog shows the whole picture.
 * Renders as HTTP 422 `{ message, conflicts }`; nothing was written.
 */
final class PlanConflictException extends RuntimeException
{
    /**
     * @param  list<PlanConflictData>  $conflicts
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct(sprintf(
            '%d occupied place(s) need a resolution before the plan can be saved.',
            count($conflicts),
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'conflicts' => array_map(
                fn (PlanConflictData $conflict): array => $conflict->toArray(),
                $this->conflicts,
            ),
        ], 422);
    }
}
