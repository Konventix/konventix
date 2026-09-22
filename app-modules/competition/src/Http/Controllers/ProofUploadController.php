<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Konventix\Competition\Actions\StoreProofImage;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * The participant's door for a proof image (design §8.7).
 *
 * Translates HTTP into `StoreProofImage` and nothing more: the policy gate
 * and the roster check live in the Action, where a console screen, a console
 * command or a later API reaches them too.
 *
 * The validation mirrors Platform's own upload endpoint
 * (`MediaAdminController::upload`) because it guards the same library, with
 * §8.7's tighter bounds: **8 MB**, **image types only**, and **never SVG**
 * (#252) — an SVG is an executable document and the media delivery route
 * answers from the brand's own origin. `mimes` judges the file's real type
 * rather than its name, and a file claiming a raster type also has to decode
 * as one, so the thumbnail step is never the first thing to notice.
 *
 * A 404 rather than a 403 for an entrant of another competition: a 403 on a
 * ULID is an answer about whether it names something.
 */
class ProofUploadController
{
    public function store(Request $request, Competition $competition, StoreProofImage $storeProofImage): JsonResponse
    {
        $validated = $request->validate([
            'entrant_id' => ['required', 'ulid'],
            'file' => [
                'required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,gif,webp',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && @getimagesize($value->getRealPath()) === false) {
                        $fail(__('The file is not a valid image.'));
                    }
                },
            ],
        ]);

        /** @var Entrant|null $entrant */
        $entrant = Entrant::query()
            ->where('competition_id', $competition->id)
            ->whereKey($validated['entrant_id'])
            ->first();

        abort_if($entrant === null, 404);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        $proofMediaId = $storeProofImage->handle(
            competition: $competition,
            entrant: $entrant,
            file: $file,
            uploadedByUserId: (string) $request->user()?->getKey(),
        );

        return response()->json(['proofMediaId' => $proofMediaId], 201);
    }
}
