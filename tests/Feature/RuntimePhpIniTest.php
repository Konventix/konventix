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
        // The safety argument for validate_timestamps=0 rests on this one:
        // the entrypoint's `artisan *:cache` calls must run with opcache off.
        'opcache.enable_cli' => '0',
        'opcache.validate_timestamps' => '0',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '30000',
        'opcache.interned_strings_buffer' => '32',
        'realpath_cache_ttl' => '600',
    ]);

    // JIT stays off until somebody measures a win and writes the number down.
    expect($directives())->not->toHaveKey('opcache.jit_buffer_size');
});

/**
 * `php.ini-production` ships `zend.assertions = -1`; PHP's compiled-in default
 * is `1`, which both compiles assertion code into every opcode array and
 * executes it. An image whose code is baked at build time can never gain an
 * `assert()` a developer meant only for a development box, and the runtime
 * pays for the check on every request. `-1` compiles them out entirely — it is
 * the one value that cannot be re-enabled at runtime, which is the point.
 */
it('compiles assertions out of the runtime image', function () use ($directives): void {
    expect($directives())->toMatchArray([
        'zend.assertions' => '-1',
    ]);
});

it('copies the ini in the runtime stage only', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    $copies = preg_grep('/^COPY\s+docker\/php\.ini\s/m', preg_split('/\R/', $dockerfile) ?: []);

    expect($copies)->toHaveCount(1);

    $offset = (int) strpos($dockerfile, (string) reset($copies));

    // Everything before the copy, and the last `FROM ... AS <stage>` in it:
    // the stage the copy actually lands in.
    preg_match_all('/^FROM\s+\S+\s+AS\s+(\S+)/m', substr($dockerfile, 0, $offset), $stages);

    expect(end($stages[1]))->toBe('runtime');
});
