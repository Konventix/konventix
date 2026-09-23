<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Konventix\Admission\Actions\IssueBadge;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Prints a badge from the operator console (D-ADM-05).
 *
 * Gated on `admission.print-badge` rather than a management capability: door
 * and desk staff print badges all day without any business redesigning them.
 *
 * The admission is read outside `BrandScope` — the operator host resolves no
 * brand at all (ADR-0029), so a plain lookup would hit the scope's zero-row
 * hardening and 404 every badge — and then held to the admin host's own
 * operator (`ScopesToOperator`): a console account of another operator that
 * knows the ULID reads 404, never somebody else's badge with a holder name of
 * its choosing (#329 final review I2). The capability check stays first, so an
 * account without it reads 403 whatever it asks for.
 */
final class BadgeController
{
    use ScopesToOperator;

    public function show(Request $request, string $admission, IssueBadge $issueBadge): SymfonyResponse
    {
        abort_unless($request->user()?->can(Permission::PrintBadge->value) ?? false, 403);

        $model = $this->findAdmission($admission);

        $bytes = $issueBadge->handle(
            admission: $model,
            issuedByUserId: (string) $request->user()->getKey(),
            holderName: $request->string('holder_name')->toString() ?: null,
        );

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="badge-%s.pdf"', $model->id),
        ]);
    }
}
