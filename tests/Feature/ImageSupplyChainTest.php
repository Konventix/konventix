<?php

declare(strict_types=1);

/**
 * #255: two ways into the release artifact that nothing checked.
 *
 * The Node install piped an unpinned remote script into root's shell at build
 * time — in the `toolchain` stage, which .gitlab-ci.yml publishes as
 * `ci-toolchain` and runs every verify, analyse and test job inside. And a
 * GitHub workflow published a second image to `ghcr.io/konventix/konventix`
 * from the same refs this project's pipeline guards, with no `needs` on the
 * tests and none of the scanners, so every bar `scan:gate` holds up could be
 * walked around by pushing to the archived remote.
 */
it('fetches no remote script into a shell during the image build', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->not->toMatch('/\|\s*(ba)?sh\b/');
});

it('pins the node image by digest', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toMatch('/^ARG NODE_IMAGE=node:\d+[^\s]*@sha256:[0-9a-f]{64}$/m')
        // Copied out of the official image rather than installed from a
        // third-party apt repository.
        ->and($dockerfile)->toContain('COPY --from=node /usr/local/bin/node');
});

/**
 * The chart pulls `registry.sxcs.de/konventix/konventix`; nothing consumes the
 * GHCR copy. A second publishing path that no consumer needs is a second
 * publishing path that only an attacker needs.
 */
it('publishes the image from no workflow but the gitlab pipeline', function (): void {
    $workflows = glob(base_path('.github/workflows/*.yml')) ?: [];

    expect($workflows)->not->toBeEmpty();

    $offenders = array_values(array_map(
        'basename',
        array_filter(
            $workflows,
            fn (string $workflow): bool => str_contains((string) file_get_contents($workflow), 'ghcr.io'),
        ),
    ));

    // Named rather than asserted one file at a time, so the failure says which.
    expect($offenders)->toBe([]);
});
