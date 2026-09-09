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

/**
 * #256: the container scan could never block a release. `allow_failure: true`
 * meant a report full of CVEs was a green job, and `release:image` carried
 * `needs: [build:image]` — under a DAG the release tag was promoted without
 * the scan's result having been looked at at all.
 *
 * The fix mirrors what the other four scanners already have: the analyzer
 * stays advisory and a gate job goes red. It cannot be `scan:gate` itself,
 * because `build:image` needs that job and container scanning needs the image
 * — so it is a sibling on the release tier, with the same rule.
 */
it('gates the release on the container scan at the same severity as every other scanner', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    expect($ci)->toContain('scan:gate:image:')
        // Release tier: a dev tag stays advisory, a candidate or release tag
        // does not.
        ->and($ci)->toMatch('/scan:gate:image:.*\n(?:.*\n)*?\s+rules: \*tier-release\b/')
        // The report has to be downloadable, or the gate reads nothing.
        ->and($ci)->toContain('- gl-container-scanning-report.json')
        // Critical and High, exactly as scan:gate judges code and dependencies.
        ->and(substr_count($ci, 'select(.severity == "Critical" or .severity == "High")'))->toBeGreaterThan(1);
});

it('makes the release promotion wait for that gate', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    $release = substr($ci, (int) strpos($ci, 'release:image:'));

    expect($release)->toMatch('/needs:(?:.*\n)*?\s+- job: scan:gate:image/');
});
