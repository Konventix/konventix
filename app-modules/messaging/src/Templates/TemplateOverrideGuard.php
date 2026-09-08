<?php

declare(strict_types=1);

namespace Konventix\Messaging\Templates;

use Konventix\Messaging\Exceptions\UnsafeTemplateException;

/**
 * An override is raw Blade, and Blade is PHP: `@php`, `{!! !!}`, `@include`,
 * and even a plain `{{ }}` echo all evaluate arbitrary PHP expressions —
 * `{{ exec('id') }}` is exactly as dangerous as `@php exec('id') @endphp`.
 * `messaging.manage-message-templates` is a brand-operator permission, not
 * a superuser one, and `TemplateAdminController::preview()` executes a
 * draft without ever saving it — a deny-list of "bad words" cannot close
 * this off, so this is an allow-list instead: an override may contain plain
 * text, a `{{ $field }}` / `{{ $field ?? 'literal' }}` echo of a payload
 * field, and `@if`/`@elseif`/`@unless`/`@isset`/`@empty`/`@foreach` gated on
 * a payload field with at most one comparison against a scalar *literal*
 * (`===`, `!==`, `<`, `<=`, `>`, `>=` — #9 added the four ordering ones so
 * a template can say "overdue" without a ternary). Everything else is
 * refused, including every other directive by name (`@php`, `@include`,
 * `@inject`, `@component`, `@livewire`, `@json`, `@method`, `@csrf`, `@dd`,
 * …) — allow-listing is what keeps this closed against a directive nobody
 * thought to deny yet.
 *
 * An `@` that Blade itself would never read as a directive is left alone:
 * Blade's directive regex demands a non-word boundary before the `@`, so
 * `info@example.com` is plain text to Blade and refusing it (#9) was the
 * guard reading danger into a signature. A *known* directive glued to a
 * preceding word stays refused — `bezahlt@else` is the trap
 * `.ai/rules/app-modules-src.md` records, where the template silently
 * stops doing what it looks like it does.
 *
 * Applied to both `subject` and `body`, before the compile check
 * (`SaveTemplateOverride`) and before rendering a preview
 * (`TemplateAdminController::preview()`) — a draft that never gets saved
 * is still executed.
 */
final class TemplateOverrideGuard
{
    /** @var list<string> */
    private const array CONDITIONAL_DIRECTIVES = ['if', 'elseif', 'unless'];

    /** @var list<string> */
    private const array IDENTITY_DIRECTIVES = ['isset', 'empty'];

    private const string FOREACH_DIRECTIVE = 'foreach';

    /** @var list<string> */
    private const array BARE_DIRECTIVES = ['else', 'endif', 'endunless', 'endisset', 'endempty', 'endforeach'];

    /**
     * The comparisons a condition may make. Identity first so `!==` is
     * never read as `!` plus something, and the two-character ordering
     * operators before their one-character prefixes.
     */
    private const string COMPARISON = '(?:===|!==|<=|>=|<|>)';

    private const string FIELD = '\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[\'"][a-zA-Z0-9_-]+[\'"]\])*';

    /** A bare `$identifier`, no index access — `@isset`/`@empty`'s narrower grammar. */
    private const string IDENTIFIER = '\$[a-zA-Z_][a-zA-Z0-9_]*';

    public function assertSafe(string $blade): void
    {
        // Refused outright, no allow-listed shape exists for any of these:
        // a raw PHP tag escapes Blade entirely, and unescaped output prints
        // whatever the expression returns without htmlspecialchars.
        foreach (['<?', '?>', '{!!'] as $forbidden) {
            $position = mb_strpos($blade, $forbidden);

            if ($position !== false) {
                throw new UnsafeTemplateException($forbidden, $position);
            }
        }

        $this->assertEchoesSafe($blade);
        $this->assertDirectivesSafe($blade);
    }

    private function assertEchoesSafe(string $blade): void
    {
        $allowed = '/^\{\{\s*'.self::FIELD.'\s*(?:\?\?\s*\'[^\'\\\\]*\')?\s*\}\}$/';

        if (preg_match_all('/\{\{.*?\}\}/s', $blade, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$echo, $offset]) {
                if (! preg_match($allowed, $echo)) {
                    throw new UnsafeTemplateException($echo, $offset);
                }
            }
        }

        // Every well-formed `{{ ... }}` pair above was stripped; anything
        // left containing `{{` is either unmatched (no closing `}}`
        // anywhere) or nested in a way the pattern above did not consume —
        // both are refused rather than guessed at.
        $remainder = preg_replace('/\{\{.*?\}\}/s', '', $blade) ?? $blade;

        $strayPosition = mb_strpos($remainder, '{{');

        if ($strayPosition !== false) {
            throw new UnsafeTemplateException('{{', $strayPosition);
        }
    }

    /**
     * Every directive name the allow-list recognises, in one list: the
     * glued-directive check has to tell "a directive in the wrong place"
     * apart from "an `@` inside a word".
     *
     * @return list<string>
     */
    private static function knownDirectives(): array
    {
        return [
            ...self::CONDITIONAL_DIRECTIVES,
            ...self::IDENTITY_DIRECTIVES,
            self::FOREACH_DIRECTIVE,
            ...self::BARE_DIRECTIVES,
        ];
    }

    private function assertDirectivesSafe(string $blade): void
    {
        if (preg_match_all('/@([a-zA-Z]+)([ \t]*\((?:[^()]|\([^()]*\))*\))?/', $blade, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        foreach ($matches[0] as $index => [$fragment, $offset]) {
            $name = $matches[1][$index][0];
            $args = trim($matches[2][$index][0] ?? '');

            // The glued-directive trap (.ai/rules/app-modules-src.md):
            // Blade's own directive regex needs a non-word boundary on
            // both sides, or the directive is left as literal text and
            // everything from the previous directive onward silently fails
            // to compile as intended.
            $before = $offset > 0 ? $blade[$offset - 1] : null;
            $after = $blade[$offset + strlen($fragment)] ?? null;
            $glued = $before !== null && preg_match('/[a-zA-Z0-9_]/', $before) === 1;

            if ($glued) {
                // Blade will not compile this one at all. Where the word
                // after the `@` is a directive this guard knows, that is
                // the silent-failure trap and the operator is told; where
                // it is not — `info@example.com`, an `@handle` inside a
                // word — it is text, and text is what the allow-list is
                // for (#9).
                if (! in_array($name, self::knownDirectives(), true)) {
                    continue;
                }

                throw new UnsafeTemplateException($fragment, $offset);
            }

            if ($after !== null && preg_match('/[a-zA-Z0-9_]/', $after) === 1) {
                throw new UnsafeTemplateException($fragment, $offset);
            }

            if (in_array($name, self::CONDITIONAL_DIRECTIVES, true)) {
                $condition = '/^\(\s*!?\s*'.self::FIELD.'(?:\s*'.self::COMPARISON.'\s*(?:\'[^\'\\\\]*\'|-?\d+|true|false|null))?\s*\)$/';

                if (! preg_match($condition, $args)) {
                    throw new UnsafeTemplateException($fragment, $offset);
                }

                continue;
            }

            if (in_array($name, self::IDENTITY_DIRECTIVES, true)) {
                if (! preg_match('/^\(\s*'.self::IDENTIFIER.'\s*\)$/', $args)) {
                    throw new UnsafeTemplateException($fragment, $offset);
                }

                continue;
            }

            if ($name === self::FOREACH_DIRECTIVE) {
                $loop = '/^\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s+as\s+\$[a-zA-Z_][a-zA-Z0-9_]*\s*\)$/';

                if (! preg_match($loop, $args)) {
                    throw new UnsafeTemplateException($fragment, $offset);
                }

                continue;
            }

            if (in_array($name, self::BARE_DIRECTIVES, true)) {
                if ($args !== '') {
                    throw new UnsafeTemplateException($fragment, $offset);
                }

                continue;
            }

            // Anything else — @php, @include, @inject, @component,
            // @livewire, @json, @method, @csrf, @dd, an unknown word — is
            // refused by not being on the list, not by being recognised as
            // dangerous.
            throw new UnsafeTemplateException($fragment, $offset);
        }
    }
}
