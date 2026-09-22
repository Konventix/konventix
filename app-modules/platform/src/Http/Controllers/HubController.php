<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Response;
use Konventix\Platform\Hub\HubPage;

/**
 * `/live` — the participant's page about the event they are at (design
 * §7.1). HTTP only: `ensure-active-event` has already decided this member
 * may be here, and `HubPage` decides what they see, so the same builder
 * can also answer `/` when the operator lets the hub replace the home page.
 */
final class HubController
{
    public function __invoke(Request $request, HubPage $page): Response
    {
        return $page->render($request);
    }
}
