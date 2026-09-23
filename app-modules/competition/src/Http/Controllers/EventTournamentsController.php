<?php

declare(strict_types=1);

namespace Konventix\Competition\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Competition\Pages\TournamentPages;

/**
 * `/events/{slug}/tournaments` (design §10): every competition of the event
 * that is not a draft. Public — no account needed to read a programme.
 *
 * The slug resolves against Competition's own `EventSummary` (ADR-0004); an
 * event of another brand, or one this module has never been told about, is a
 * 404.
 */
final class EventTournamentsController
{
    public function __invoke(string $slug, TournamentPages $pages): Response
    {
        return Inertia::render('competition/EventTournaments', [
            'page' => $pages->index($pages->event($slug)),
        ]);
    }
}
