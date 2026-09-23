{{--
    Shell for the offline entrance client (ADR-0002's runtime carve-out).

    Deliberately NOT an Inertia page: Inertia needs a server response before it
    can render, and this client exists precisely for when there is no server to
    respond. The shell is static, the service worker precaches it, and every
    decision the door makes afterwards comes from IndexedDB.

    It carries no brand theming and no server-injected state — a scanner
    power-cycled at a venue with a dead uplink must boot from cache alone.
    The one exception is the locale chain (ADR-0068) the client picks its
    words by: it is baked into the cached copy, so the door keeps speaking
    the language it was set up in.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#111827">
    <link rel="manifest" href="/entrance.webmanifest">
    <title>{{ __('Konventix Entrance') }}</title>
    {{-- app.css carries the ADR-0008 design tokens the door's styles
         reference; it is precached alongside the client bundle. --}}
    @vite(['resources/css/app.css', 'resources/js/entrance/entrance-client.ts'])
</head>
<body>
    <div id="entrance" data-locale-chain='@json(app(\Konventix\Platform\Locales\Locales::class)->chain(app()->getLocale()))'></div>
</body>
</html>
