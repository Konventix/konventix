<?php

declare(strict_types=1);

namespace Konventix\Resources\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The editor's `expectedVersion` is not the plan's current version (design
 * §6.1 step 0, Q11: version check plus conflict refusal, no locks). Renders
 * itself as HTTP 409 `{ message, currentVersion }` so every controller that
 * calls `SavePlan` answers the same shape without a catch block.
 */
final class PlanStaleException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(sprintf(
            'The plan was saved by somebody else; it is now at version %d. Reload before saving again.',
            $currentVersion,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'currentVersion' => $this->currentVersion,
        ], 409);
    }
}
