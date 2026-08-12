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
            Brand token injection.

            The Brand context resolves the active tenant's palette and shares it
            as a `brandTokens` view variable — a plain map of design-token name
            to CSS color, e.g. ['accent' => 'hsl(210 90% 45%)']. Only the raw
            token layer from resources/css/app.css may be overridden here; every
            Tailwind utility is derived from it via `@theme inline`, so this
            block re-colors the whole app.

            TODO(brand): implement the middleware that resolves the tenant and
            shares `$brandTokens`. Until then this renders nothing.
        --}}
        @if (! empty($brandTokens ?? []))
            <style id="konventix-brand-tokens">
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
            </style>
        @endif

        {{-- TODO(brand): replace these starter-kit icons with Konventix artwork. --}}
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Konventix') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
