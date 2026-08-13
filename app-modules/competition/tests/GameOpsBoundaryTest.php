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
