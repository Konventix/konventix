<?php

declare(strict_types=1);

/**
 * #254: the runtime image loaded no php.ini at all, so it ran on PHP's
 * compiled-in defaults — `display_errors=STDOUT`, `log_errors=Off`,
 * `expose_php=On`, `memory_limit=128M`, `opcache.validate_timestamps=On`.
 *
 * The file itself is the artifact under test here: a Pest suite cannot build
 * a container, but it can pin the values the container is built from, and
 * pin that the copy happens in the runtime stage only — the toolchain stage
 * is the published CI image and must keep taking `.gitlab-ci.yml`'s
 * `memory_limit = -1` override (`99-ci.ini`).
 */
$directives = function (): array {
    $parsed = parse_ini_file(base_path('docker/php.ini'), false, INI_SCANNER_RAW);

    expect($parsed)->toBeArray();

    return array_change_key_case((array) $parsed, CASE_LOWER);
};

it('ships a runtime php.ini that closes the error-disclosure defaults', function () use ($directives): void {
    expect($directives())->toMatchArray([
        'expose_php' => 'Off',
        'display_errors' => 'Off',
        'display_startup_errors' => 'Off',
        'log_errors' => 'On',
        'error_log' => '/dev/stderr',
        'memory_limit' => '512M',
    ]);
});

/**
 * The ingress caps a request body at 12m
 * (`nginx.ingress.kubernetes.io/proxy-body-size`). PHP refusing first, with
 * a message the application can render, beats nginx refusing with its own
 * error page — and PHP refusing *later* than nginx would be a limit nobody
 * ever reaches.
 */
it('matches the ingress body size limit', function () use ($directives): void {
    expect($directives())->toMatchArray([
        'upload_max_filesize' => '12M',
        'post_max_size' => '12M',
    ]);
});

/**
 * The code in the image never changes: it is baked at build time and the root
 * filesystem is read-only. `validate_timestamps=0` is therefore free
 * correctness-wise and saves a `stat()` per file every two seconds — safe
 * because the entrypoint's `artisan *:cache` calls run as CLI, where opcache
 * is off, and finish before `exec "$@"`.
 */
it('configures opcache for code that cannot change under it', function () use ($directives): void {
    expect($directives())->toMatchArray([
        'opcache.enable' => '1',
        'opcache.validate_timestamps' => '0',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '30000',
        'opcache.interned_strings_buffer' => '32',
    ]);

    // JIT stays off until somebody measures a win and writes the number down.
    expect($directives())->not->toHaveKey('opcache.jit_buffer_size');
});

it('copies the ini in the runtime stage only', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    $copies = preg_grep('/^COPY\s+docker\/php\.ini\s/m', preg_split('/\R/', $dockerfile) ?: []);

    expect($copies)->toHaveCount(1);

    $line = (string) array_key_first($copies);
    $offset = (int) strpos($dockerfile, (string) reset($copies));

    // Everything before the copy, and the last `FROM ... AS <stage>` in it:
    // the stage the copy actually lands in.
    preg_match_all('/^FROM\s+\S+\s+AS\s+(\S+)/m', substr($dockerfile, 0, $offset), $stages);

    expect(end($stages[1]))->toBe('runtime')
        ->and($line)->toBeGreaterThan(0);
});
