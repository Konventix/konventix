<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Pages\TournamentPages;

/**
 * A team's page (design §10): its roster and its matches across the event.
 * Public, and reached from a competition the team is entered in; a team that
 * is not entered there is a 404.
 */
final class TeamPageController
{
    public function __invoke(string $slug, string $competition, string $team, TournamentPages $pages): Response
    {
        $event = $pages->event($slug);

        return Inertia::render('competition/Team', [
            'page' => $pages->teamPage($event, $pages->competition($event, $competition), $team),
        ]);
    }
}
