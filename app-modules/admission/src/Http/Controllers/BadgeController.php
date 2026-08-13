<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Konventix\Admission\Actions\IssueBadge;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Models\Admission;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Prints a badge from the operator console (D-ADM-05).
 *
 * Gated on `admission.print-badge` rather than a management capability: door
 * and desk staff print badges all day without any business redesigning them.
 *
 * `withoutBrandScope()` scoped to the admission's own brand is the named
 * escape hatch (ADR-0010) — the operator host resolves no brand at all
 * (ADR-0029), so a plain lookup would hit BrandScope's zero-row hardening and
 * 404 every badge.
 */
final class BadgeController
{
    public function show(Request $request, string $admission, IssueBadge $issueBadge): SymfonyResponse
    {
        abort_unless($request->user()?->can(Permission::PrintBadge->value) ?? false, 403);

        /** @var Admission $model */
        $model = Admission::withoutBrandScope()->whereKey($admission)->firstOrFail();

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
