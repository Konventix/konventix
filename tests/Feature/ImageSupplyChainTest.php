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
 * #256 made the container scan able to block a release: `scan:gate:image`
 * read the report and `release:image` waited for it. That held until the
 * v0.6.4 release spent two days behind the scanners (pipelines 1828 and
 * 1833), and the trade was reversed: every scanner and both gates are manual
 * and advisory, and nothing in the publishing chain waits for one. The gate
 * itself stays — pressing `container_scanning` and then this job gives the
 * old verdict — but it must be *unable* to block: `when: manual` on every
 * rule and an explicit `allow_failure: true`, because the security templates
 * set `false` and a manual job that may not fail holds the pipeline while
 * it waits to be played.
 */
it('keeps the container gate manual and advisory', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    expect($ci)->toContain('scan:gate:image:');

    $gate = substr($ci, (int) strpos($ci, 'scan:gate:image:'));
    $gate = substr($gate, 0, (int) strpos($gate, "\n\n########## "));

    preg_match('/\n  rules:\n((?:    .*\n)+)/', $gate, $rules);

    expect($rules)->not->toBeEmpty('scan:gate:image has no rules block')
        ->and($rules[1])->toMatch('/- if: \$CI_COMMIT_TAG\n\s+when: manual/')
        ->and($rules[1])->not->toMatch('/- if: (?!\$PIPELINE_SHAPE)[^\n]*\n(?!\s+when: manual)/')
        ->and($gate)->toMatch('/^  allow_failure: true$/m')
        // The report is read by stage order, not `needs:` — a job that needs
        // an unplayed manual job is skipped and can never be played.
        ->and($gate)->toMatch('/^  dependencies:\n\s+- container_scanning/m')
        ->and($gate)->not->toMatch('/^  needs:/m')
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

it('lets no scan job into the publishing chain', function (): void {
    $ci = (string) file_get_contents(base_path('.gitlab-ci.yml'));

    $publish = substr($ci, (int) strpos($ci, "\npublish:image:"));
    $publish = substr($publish, 0, (int) strpos($publish, "\n  script:"));
    $release = substr($ci, (int) strpos($ci, "\nrelease:image:"));
    $release = substr($release, 0, (int) strpos($release, "\n  script:"));
    // The comments may say what the job no longer waits for; the keys may not.
    $release = (string) preg_replace('/^\s*#.*\n/m', '', $release);
    $publish = (string) preg_replace('/^\s*#.*\n/m', '', $publish);

    expect($publish)->toMatch('/needs:(?:.*\n)*?\s+- test:php/')
        ->and($publish)->toMatch('/needs:(?:.*\n)*?\s+- build:image/')
        ->and($publish)->not->toMatch('/needs:(?:.*\n)*?\s+- (?:job: )?scan:gate\b/')
        ->and($release)->toMatch('/needs:(?:.*\n)*?\s+- job: publish:image/')
        ->and($release)->not->toContain('scan:gate:image')
        ->and($release)->not->toContain('container_scanning');
});
