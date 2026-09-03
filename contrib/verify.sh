#!/usr/bin/env bash
#
# The fast verification run (#64): format check, phpstan, and only the tests
# the change can have broken. Full verification is the pipeline's job; this
# is what an agent runs after every change, so it has to stay well under a
# minute for a change inside one module.
#
#   vendor/bin/sail composer verify                       changed paths decide
#   vendor/bin/sail composer verify -- commerce identity  named modules
#
# Runs inside the Sail container, like every other test command here. What
# counts as changed is the working tree against the merge base with develop,
# so committed-but-unmerged work is checked too, plus untracked files.
# contrib/verify-plan.sh turns those paths into test directories.
set -eu
cd "$(dirname "$0")/.."

started=$(date +%s)
step() { printf '\n== %s (%ss)\n' "$1" "$(( $(date +%s) - started ))"; }

base=$(git merge-base HEAD origin/develop 2>/dev/null || git rev-parse HEAD)
changed=$( { git diff --name-only "$base" --; git ls-files --others --exclude-standard; } | sort -u )

php_files=()
while IFS= read -r file; do
    case "$file" in *.php) [ -f "$file" ] && php_files+=("$file") ;; esac
done <<< "$changed"

if [ "${#php_files[@]}" -gt 0 ]; then
    step "pint on ${#php_files[@]} changed PHP file(s)"
    vendor/bin/pint --test "${php_files[@]}"
    step "phpstan"
    composer types:check --quiet
else
    step "no PHP changed: pint and phpstan skipped"
fi

plan=$(printf '%s\n' "$changed" | bash contrib/verify-plan.sh "$@")

dirs=()
run_js=
owe_full=
while IFS= read -r line; do
    case "$line" in
        '') ;;
        js) run_js=yes ;;
        full) owe_full=yes ;;
        *)
            if [ -d "$line" ]; then
                dirs+=("$line")
            else
                echo "-- $line does not exist: nothing to run there."
            fi
            ;;
    esac
done <<< "$plan"

if [ "${#dirs[@]}" -gt 0 ]; then
    step "pest: ${dirs[*]}"
    php artisan test --compact "${dirs[@]}"
fi

if [ -n "$run_js" ]; then
    step "vitest"
    npm run test:js --silent
fi

if [ "${#dirs[@]}" -eq 0 ] && [ -z "$run_js" ]; then
    step "no tests selected by the changed paths"
fi

step "done"
if [ -n "$owe_full" ]; then
    echo "The change reaches every test (config, routes, database, platform or the test bootstrap)."
    echo "The full suite is owed and the merge request pipeline pays it; run it locally only if"
    echo "you need the answer before pushing: composer test"
fi
