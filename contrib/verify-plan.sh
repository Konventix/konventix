#!/usr/bin/env bash
#
# Which tests can a change have broken? Changed paths in (stdin, one per
# line), test directories out (stdout, sorted, unique). This is the part of
# `composer verify` (#64) that is worth pinning with a test — the runner
# around it, contrib/verify.sh, is glue.
#
# Two marker lines say what a directory cannot:
#   js     vitest has something to run
#   full   the change reaches every test, so the whole Pest suite is still
#          owed before the merge request — the fast run does not pay it
#
# Module names as arguments replace the mapping: `verify-plan.sh commerce`.
set -eu

if [ "$#" -gt 0 ]; then
    for module in "$@"; do
        printf 'app-modules/%s/tests\n' "$module"
    done | sort -u
    exit 0
fi

emit() { printf '%s\n' "$@"; }

while IFS= read -r path; do
    [ -n "$path" ] || continue
    case "$path" in
        app-modules/*)
            module=${path#app-modules/}
            module=${module%%/*}
            emit "app-modules/$module/tests"
            # Platform is the kernel every module is built on: brand context,
            # permissions, the shared models. Its own tests are the fast
            # answer; the honest one is everything.
            if [ "$module" = platform ]; then emit full; fi
            case "$path" in
                app-modules/*/resources/js/*) emit js ;;
            esac
            ;;
        app/*)
            emit tests/Feature tests/Unit
            ;;
        # Config, routes, migrations, the dependency set and the test
        # bootstrap are read by every test there is.
        config/*|routes/*|database/*|composer.json|composer.lock|phpunit.xml|tests/TestCase.php|tests/Pest.php)
            emit full tests/Feature tests/Unit
            ;;
        tests/Feature/*) emit tests/Feature ;;
        tests/Unit/*) emit tests/Unit ;;
        resources/js/*) emit js ;;
        resources/views/*) emit tests/Feature ;;
        # The verification scripts themselves are covered by tests/Unit.
        contrib/*) emit tests/Unit ;;
        *) ;;
    esac
done | sort -u
