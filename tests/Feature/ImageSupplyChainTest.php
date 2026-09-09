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
 * — so it is a sibling on the release tier.
 */
it('gates the release on the container scan', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    expect($ci)->toContain('scan:gate:image:')
        // Release tier: a dev tag stays advisory, a candidate or release tag
        // does not.
        ->and($ci)->toMatch('/scan:gate:image:.*\n(?:.*\n)*?\s+rules: \*tier-release\b/')
        // The report has to be downloadable, or the gate reads nothing.
        ->and($ci)->toContain('- gl-container-scanning-report.json');
});

/**
 * The bar is Critical, which is #256's criterion and one notch above
 * `scan:gate`'s High — and a Critical only blocks when something can be done
 * about it. Pipeline 1674 (v0.5.4) was stopped by seventeen Criticals in the
 * base image's Debian packages, every one of them with an empty `solution`:
 * no fixed version exists, so no merge in this repository could have made the
 * gate green. A finding without a fix is now printed as informational; a
 * Critical with one blocks. High stays informational either way.
 *
 * `CS_SEVERITY_THRESHOLD` stays unset on purpose: it would make the analyzer
 * the job that fails, and the house rule is that analyzers are green with
 * findings and a gate goes red.
 */
it('blocks the promotion only on a Critical that has a fix', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    $gate = substr($ci, (int) strpos($ci, 'scan:gate:image:'));
    $gate = substr($gate, 0, (int) strpos($gate, 'release:image:'));

    expect($gate)->toContain('select(.severity == "Critical" and ((.solution // "") != ""))')
        ->and($gate)->toContain('select(.severity == "Critical" and ((.solution // "") == ""))')
        ->and($gate)->toContain('select(.severity == "High")')
        // Counted separately, and only the branch with a fix sets the exit
        // status. The unfixed ones are printed under their own heading.
        ->and($gate)->toMatch('/blocking=\$\(jq/')
        ->and($gate)->toMatch('/unfixed=\$\(jq/')
        ->and($gate)->toContain('unfixed, not blocking')
        ->and($gate)->toMatch('/if \[ "\$blocking" -gt 0 \]; then(?:.*\n)*?\s+status=1/')
        ->and($gate)->not->toMatch('/if \[ "\$unfixed" -gt 0 \]; then(?:(?!fi)[\s\S])*status=1/')
        ->and($gate)->not->toMatch('/if \[ "\$high" -gt 0 \]; then(?:(?!fi)[\s\S])*status=1/')
        ->and($gate)->not->toContain('CS_SEVERITY_THRESHOLD');

    // The container report carries no `name`; printing it is what put a column
    // of `null` in pipeline 1674's log. The CVE id comes out of `identifiers`.
    expect($gate)->toContain('select(.type == "cve")')
        ->and($gate)->not->toContain('\(.name)');
});

/**
 * The same rule, in the other gate: a dependency finding at High or above
 * blocks when the analyzer names a fixed version and is informational when it
 * does not. SAST is left alone — a code finding has no fix to wait for, the
 * fix is the change. #256 says the rule moves in both gates or in neither.
 */
it('applies the same unfixed rule to the dependency report and leaves sast alone', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    $gate = substr($ci, (int) strpos($ci, 'scan:gate:'));
    $gate = substr($gate, 0, (int) strpos($gate, 'build:image:'));

    expect($gate)->toContain('select((.severity == "Critical" or .severity == "High") and ((.solution // "") != ""))')
        ->and($gate)->toContain('select((.severity == "Critical" or .severity == "High") and ((.solution // "") == ""))')
        ->and($gate)->toContain('unfixed, not blocking')
        // SAST keeps the plain severity selection and its own report name.
        ->and($gate)->toContain('select(.severity == "Critical" or .severity == "High")')
        ->and($gate)->toContain('gl-sast-report.json');
});

it('makes the release promotion wait for that gate', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    $release = substr($ci, (int) strpos($ci, 'release:image:'));

    expect($release)->toMatch('/needs:(?:.*\n)*?\s+- job: scan:gate:image/');
});
