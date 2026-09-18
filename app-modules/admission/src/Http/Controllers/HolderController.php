<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Admission\Actions\AssignHolder;
use Konventix\Admission\Actions\UnassignHolder;
use Konventix\Admission\Exceptions\HolderChangeRefusedException;
use Konventix\Admission\Http\Concerns\FindsManagedAdmission;
use Konventix\Admission\Models\Admission;

/**
 * The manager's two holder actions on one of their admissions (#292 §4.3):
 * "this one is mine" and "take it back". Ownership is the whole
 * authorisation; the Actions decide everything else and their refusals
 * come back as a form error the ticket manager shows under the row.
 *
 * `store()` passes the manager's own id and nothing else (§12 point 4):
 * the request carries no holder, so a manager cannot name a third person
 * without that person claiming for themselves.
 */
final class HolderController
{
    use FindsManagedAdmission;

    public function store(Request $request, Admission $admission, AssignHolder $assign): RedirectResponse
    {
        $admission = $this->managedAdmission($request, $admission);
        $userId = (string) $request->user()->getAuthIdentifier();

        try {
            $assign->handle($admission, $userId, AssignHolder::ACTOR_MANAGER, $userId);
        } catch (HolderChangeRefusedException $exception) {
            throw ValidationException::withMessages(['holder' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('This ticket is now yours.')]);

        return back();
    }

    public function destroy(Request $request, Admission $admission, UnassignHolder $unassign): RedirectResponse
    {
        $admission = $this->managedAdmission($request, $admission);
        $userId = (string) $request->user()->getAuthIdentifier();

        try {
            $unassign->handle($admission, UnassignHolder::ACTOR_MANAGER, $userId, UnassignHolder::REASON_MANAGER);
        } catch (HolderChangeRefusedException $exception) {
            throw ValidationException::withMessages(['holder' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The ticket is unassigned again.')]);

        return back();
    }
}
