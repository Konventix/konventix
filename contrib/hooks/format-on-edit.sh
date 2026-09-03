#!/usr/bin/env bash
#
# PostToolUse hook (#64): formatting and linting are not agent decisions.
# After every Edit or Write the file the tool touched is checked and, only
# when it needs it, rewritten in place — pint for PHP, prettier and
# `eslint --fix` for the frontend. It never blocks: a formatter that cannot
# run says so and exits 0. The one thing handed back loudly (exit 2, which
# Claude Code shows the agent) is an eslint finding that --fix could not
# settle, because that is a lint failure the pipeline would report anyway.
#
# Wired in .claude/settings.json. Claude Code reads that file only from the
# directory a session starts in, so a session started in the multi-repo
# parent needs the same hook entry in its own .claude/settings.json,
# pointing at this script. The script finds the application root from the
# edited file, not from $CLAUDE_PROJECT_DIR, so both placements work.
set -u

input=$(cat)
if command -v jq >/dev/null 2>&1; then
    file=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')
else
    file=$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)
fi
[ -n "$file" ] && [ -f "$file" ] || exit 0

# The application root: the nearest ancestor with composer.json and Sail.
root=$(dirname "$file")
while [ "$root" != "/" ]; do
    if [ -f "$root/composer.json" ] && [ -x "$root/vendor/bin/sail" ]; then
        break
    fi
    root=$(dirname "$root")
done
[ "$root" != "/" ] || exit 0
rel=${file#"$root"/}
cd "$root" || exit 0

reread="read it again before editing it further"

case "$rel" in
    *.php)
        # Pint runs inside the container: the host PHP is not the project's,
        # and a formatter on the wrong PHP version is worse than none.
        if ! vendor/bin/sail ps --status running --services 2>/dev/null | grep -qx 'laravel.test'; then
            echo "format-on-edit: Sail is not running, pint skipped for $rel"
            exit 0
        fi
        if ! vendor/bin/sail pint --test "$rel" >/dev/null 2>&1; then
            if vendor/bin/sail pint "$rel" >/dev/null 2>&1; then
                echo "format-on-edit: pint reformatted $rel — $reread"
            else
                echo "format-on-edit: pint could not format $rel" >&2
            fi
        fi
        ;;
    resources/*|app-modules/*/resources/*)
        case "$rel" in
            *.ts|*.tsx|*.vue|*.js|*.css|*.json) ;;
            *) exit 0 ;;
        esac
        if ! command -v npx >/dev/null 2>&1 || [ ! -d node_modules ]; then
            echo "format-on-edit: npx or node_modules missing, prettier and eslint skipped for $rel"
            exit 0
        fi
        if ! npx prettier --check "$rel" >/dev/null 2>&1; then
            if npx prettier --write "$rel" >/dev/null 2>&1; then
                echo "format-on-edit: prettier reformatted $rel — $reread"
            else
                echo "format-on-edit: prettier could not format $rel" >&2
            fi
        fi
        case "$rel" in
            *.ts|*.tsx|*.vue|*.js)
                before=$(cksum < "$rel")
                if ! output=$(npx eslint --fix "$rel" 2>&1); then
                    printf '%s\n' "$output" >&2
                    echo "format-on-edit: eslint reports problems in $rel that --fix could not settle" >&2
                    exit 2
                fi
                if [ "$before" != "$(cksum < "$rel")" ]; then
                    echo "format-on-edit: eslint --fix changed $rel — $reread"
                fi
                ;;
        esac
        ;;
esac
exit 0
