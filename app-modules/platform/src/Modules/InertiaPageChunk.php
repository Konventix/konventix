<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules;

/**
 * Maps an Inertia component name to the source path Vite knows it by, for
 * the root template's eager `@vite` page-chunk preload. Pages resolve from
 * the host's `resources/js/pages` or any module's
 * `app-modules/<name>/resources/js/pages` (mirror of the client-side
 * resolver in resources/js/app.ts — the two must agree).
 *
 * Returns null for a component no source file matches; the template then
 * simply skips the preload and the client resolver reports the real error.
 */
class InertiaPageChunk
{
    public static function path(string $component): ?string
    {
        $host = "resources/js/pages/{$component}.vue";

        if (is_file(base_path($host))) {
            return $host;
        }

        $matches = glob(base_path("app-modules/*/resources/js/pages/{$component}.vue")) ?: [];

        if ($matches === []) {
            return null;
        }

        return ltrim(str_replace(base_path(), '', $matches[0]), '/');
    }
}
