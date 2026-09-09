<?php

declare(strict_types=1);

/**
 * #257: the runtime user owned the whole application tree and could write it,
 * and the image carried no health check of its own.
 *
 * Both are facts about the Dockerfile, and a Pest suite cannot build a
 * container — but it can pin the shape the build is made from, which is what
 * a later edit would quietly undo. CI's `build:image` job is what proves the
 * result actually runs.
 */
$dockerfile = fn (): string => (string) file_get_contents(base_path('Dockerfile'));

/**
 * Every PHP file, the entrypoint and the `/opt/bootstrap-cache` seed belonged
 * to the UID the server runs as, so any write primitive in the application was
 * code execution — and, wherever the root filesystem is not read-only,
 * persistence past the next boot. `storage` and `bootstrap/cache` are the only
 * two paths that have to be written, and both are mounts in the deployment
 * anyway.
 */
it('gives the runtime user only the directories it has to write', function () use ($dockerfile): void {
    $contents = $dockerfile();

    $copies = preg_grep('/^COPY\s+--from=build\s/m', preg_split('/\R/', $contents) ?: []);

    expect($copies)->toHaveCount(2);

    foreach ($copies as $copy) {
        expect($copy)->toContain('--chown=root:root');
    }

    expect($contents)->toMatch('/chown -R konventix:konventix storage bootstrap\/cache/')
        // The base image leaves the WORKDIR at www-data:www-data 1777, so the
        // ownership above would still leave the runtime user able to create
        // files beside the application.
        ->and($contents)->toContain('chmod 755 /var/www/html');

    // The chown has to happen before the image drops privileges, or it cannot.
    expect(strpos($contents, 'chown -R konventix:konventix'))
        ->toBeLessThan((int) strpos($contents, 'USER konventix'));
});

/**
 * The base image ships one — `curl -f http://localhost:2019/metrics`, Caddy's
 * admin API — which reports on a listener this image should not be exposing at
 * all (#257's Caddyfile item) rather than on the application. The chart's web
 * liveness probe is an HTTP GET of `/up`; the image says the same thing, so a
 * `docker run` or Compose consumer gets the same answer Kubernetes does.
 */
it('health-checks the application on the path the chart probes', function () use ($dockerfile): void {
    expect($dockerfile())->toMatch('/^HEALTHCHECK .*\n?.*127\.0\.0\.1:8000\/up/m');
});
