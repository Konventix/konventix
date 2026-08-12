<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Konventix\Platform\Models\Operator;

/**
 * The operator resolved for an admin-host request (ADR-0029).
 *
 * Mutually exclusive with a resolved brand: a request runs either in a brand
 * (public surfaces) or as the operator (admin console), never both. Scoped
 * binding for the same Octane reason as BrandContext.
 */
class OperatorContext
{
    private ?Operator $operator = null;

    public function set(Operator $operator): void
    {
        $this->operator = $operator;
    }

    public function forget(): void
    {
        $this->operator = null;
    }

    public function current(): ?Operator
    {
        return $this->operator;
    }

    public function id(): ?string
    {
        return $this->operator?->getKey();
    }

    public function hasOperator(): bool
    {
        return $this->operator instanceof Operator;
    }
}
