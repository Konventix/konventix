<?php

declare(strict_types=1);

/**
 * Competition's TODO asks for this outright: "Add an arch test proving
 * Competition imports no GameOps class."
 *
 * It matters more than a usual boundary test, because GameOps does not exist
 * yet. The dependency between the two modules runs **one way** — Competition
 * emits `MatchScheduled` and `MatchFinished`, GameOps listens — and the only
 * thing keeping it that way while GameOps is unbuilt is this test. Without
 * it, the first person to build GameOps could quietly wire the reverse
 * direction and nothing would object.
 *
 * @return list<array{path: string, contents: string}>
 */
function competitionSourceFiles(): array
{
    $directory = new RecursiveDirectoryIterator(
        base_path('app-modules/competition/src'),
        FilesystemIterator::SKIP_DOTS,
    );

    $files = [];

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = [
                'path' => str_replace(base_path().'/', '', $file->getPathname()),
                'contents' => (string) file_get_contents($file->getPathname()),
            ];
        }
    }

    return $files;
}

it('never imports a Konventix\GameOps class', function (): void {
    $violations = [];

    foreach (competitionSourceFiles() as $file) {
        if (preg_match('/^use\s+Konventix\\\\GameOps\\\\/m', $file['contents']) === 1) {
            $violations[] = $file['path'];
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Competition must never import a GameOps class. GameOps consumes',
        'MatchScheduled/MatchFinished; the dependency runs one way (ADR-0012).',
        '',
        ...$violations,
    ]));
});

it('references a GameOps game only as a plain ULID', function (): void {
    // ADR-0024: "Discipline references the game by GameOps game ULID rather
    // than Competition storing game_id directly" as a foreign relation. A
    // belongsTo here would be the coupling ADR-0004 forbids.
    $discipline = (string) file_get_contents(
        base_path('app-modules/competition/src/Models/Discipline.php')
    );

    expect($discipline)->not->toContain('belongsTo(Game')
        ->and($discipline)->toContain('game_id');
});

it('keeps the GameOps-facing events free of anything but scalars and ULIDs', function (): void {
    // GameOps is written against these payloads even though it does not exist
    // yet, so they must not drift into carrying models (ADR-0004).
    foreach (['MatchScheduled', 'MatchFinished'] as $event) {
        $source = (string) file_get_contents(
            base_path("app-modules/competition/src/Events/{$event}.php")
        );

        expect($source)->not->toContain('Konventix\\Competition\\Models');
    }
});

it('never imports an Identity model, only Identity events', function (): void {
    // #297 acceptance criterion 3, and design §10: "every page shows names
    // from ParticipantProfile, never from Identity". Identity owns accounts;
    // the profile of record travels on `UserProfileUpdated` (ADR-0051 §6) and
    // is projected into this module's own read model.
    //
    // The `Konventix\Identity\Events\` import is the sanctioned exception —
    // events are the public contract between modules (ADR-0004) — so this
    // test forbids the *models* namespace precisely, not the whole vendor.
    $violations = [];

    foreach (competitionSourceFiles() as $file) {
        if (preg_match('/^use\s+Konventix\\\\Identity\\\\Models\\\\/m', $file['contents']) === 1) {
            $violations[] = $file['path'];
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'Competition must never import an Identity model. Users are referenced',
        'by plain ULID (ADR-0014) and their names come from ParticipantProfile,',
        'projected from UserProfileUpdated (ADR-0004, design §10).',
        '',
        ...$violations,
    ]));
});

it('keeps the roster-carrying events free of anything but scalars and ULIDs', function (): void {
    // `EntrantRegistered` and `EntrantWithdrawn` gained `member_user_ids`
    // (#297). A roster is a list of ULIDs and must stay one: the moment a
    // model leaks onto a contract payload, every consumer is coupled to this
    // module's schema (ADR-0004).
    foreach (['EntrantRegistered', 'EntrantWithdrawn'] as $event) {
        $source = (string) file_get_contents(
            base_path("app-modules/competition/src/Events/{$event}.php")
        );

        expect($source)->not->toContain('Konventix\\Competition\\Models')
            ->and($source)->not->toContain('Konventix\\Identity')
            ->and($source)->toContain('final readonly class');
    }
});

it('keeps every Competition contract event final and readonly', function (): void {
    // ADR-0004: a payload a consumer can mutate is not a contract. Cheap to
    // assert here, and it catches the event somebody adds in a hurry.
    $directory = new DirectoryIterator(base_path('app-modules/competition/src/Events'));

    foreach ($directory as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))
            ->toContain('final readonly class', "{$file->getFilename()} must declare a final readonly class.");
    }
});
