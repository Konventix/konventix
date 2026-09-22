<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color before app.css loads --}}
        <style>
            html {
                background-color: hsl(0 0% 100%);
            }

            html.dark {
                background-color: hsl(0 0% 3.9%);
            }
        </style>


        {{--
            The resolved brand's favicon when it has uploaded one, the bundled
            Konventix set otherwise (konventix-brand, assets/export/web: the
            SVG swaps Ink for Travertine under a dark colour scheme, the .ico
            carries 16/32/48 for browsers without SVG favicon support).
            `$brandIdentity` is shared by ResolveBrand; the null guard covers
            responses rendered before it runs (an early error page), which
            fall back to the bundled set. The manifest names Konventix and its
            icons, so it goes with the bundled set only: a brand that wears its
            own favicon is not offered a Konventix install.
        --}}
        @if (($brandIdentity->faviconUrl ?? null) !== null)
            <link rel="icon" href="{{ $brandIdentity->faviconUrl }}">
            <link rel="apple-touch-icon" href="{{ $brandIdentity->faviconUrl }}">
        @else
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
            <link rel="apple-touch-icon" href="/apple-touch-icon.png">
            <link rel="manifest" href="/manifest.json">
        @endif

        @fonts

        {{-- Page chunk may live in the host or any module's pages dir (.ai/rules/resources.md). --}}
        @vite(array_values(array_filter([
            'resources/css/app.css',
            'resources/js/app.ts',
            \Konventix\Platform\Modules\InertiaPageChunk::path($page['component']),
        ])))

        {{--
            Brand token injection.

            The Brand context resolves the active tenant's palette and shares it
            as a `brandTokens` view variable — a plain map of design-token name
            to CSS color, e.g. ['accent' => 'hsl(210 90% 45%)']. Only the raw
            token layer from resources/css/app.css may be overridden here; every
            Tailwind utility is derived from it via `@theme inline`, so this
            block re-colors the whole app.

            It is emitted AFTER @vite, and that position is the whole point:
            `resources/css/app.css` declares the same raw tokens on `:root`
            at the same specificity, so whichever comes last wins. While this
            block stood above the stylesheet, every brand's saved palette was
            silently overridden by the defaults — the console preview looked
            right because it sets the tokens on an element it owns, and the
            page rendered a `<style>` that never applied (0.8.1).
        --}}
        @if (! empty($brandTokens ?? []) || ! empty($brandTokensDark ?? []))
            <style id="konventix-brand-tokens">
                @if (! empty($brandTokens ?? []))
                :root {
                    @foreach ($brandTokens as $token => $value)
                        {{-- Values are tenant-controlled: allow only token-shaped
                             names and color-shaped values, so nothing can break
                             out of the declaration or the style element. --}}
                        @continue (! preg_match('/^[a-z0-9-]{1,64}$/', (string) $token))
                        @continue (! preg_match('/^[#a-zA-Z0-9 ,.%()\/-]{1,64}$/', (string) $value))
                        --{{ $token }}: {{ $value }};
                    @endforeach
                }
                @endif

                {{-- The dark map lands on `.dark` rather than `:root`, so the
                     two never compete for the same declaration and a brand that
                     themes only light mode says nothing about dark at all.
                     Emitted only when there is something to say — an empty
                     block would still override nothing, but it would suggest
                     the brand had made a choice it has not made. --}}
                @if (! empty($brandTokensDark ?? []))
                .dark {
                    @foreach ($brandTokensDark as $token => $value)
                        @continue (! preg_match('/^[a-z0-9-]{1,64}$/', (string) $token))
                        @continue (! preg_match('/^[#a-zA-Z0-9 ,.%()\/-]{1,64}$/', (string) $value))
                        --{{ $token }}: {{ $value }};
                    @endforeach
                }
                @endif
            </style>
        @endif
        <x-inertia::head>
            {{-- The brand's name, not the deployment's: a visitor on a brand
                 host never sees the operator's application name (ADR-0001). --}}
            <title>{{ $brandIdentity->name ?? config('app.name', 'Konventix') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
