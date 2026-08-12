<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

use Konventix\Platform\Models\StaticPage;

/**
 * Turns a static page's stored `{type, data}` block list into the
 * `{key, component, props}` shape `StaticPage.vue` consumes.
 *
 * Shared by `StaticPageController` (`/pages/{slug}`) and the `/` landing
 * route (host `routes/web.php`, which renders the brand's `home`-slug page
 * the same way), so both resolve blocks through the same registry gate and
 * the same optional server-side resolution hook ({@see ResolvesBlockProps}).
 *
 * Blocks whose type is unknown or whose module is inactive for the brand are
 * skipped, not errors: a page survives a module being switched off.
 */
class StaticPageRenderer
{
    public function __construct(private readonly BlockRegistry $registry) {}

    /**
     * @return array<int, array{key: string, component: string, props: array<string, mixed>}>
     */
    public function render(StaticPage $page): array
    {
        $rendered = [];

        foreach ($page->blocks ?? [] as $block) {
            $type = $this->registry->resolveActive((string) ($block['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = $block['data'] ?? [];

            $rendered[] = [
                'key' => $type->key(),
                'component' => $type->component(),
                'props' => $type instanceof ResolvesBlockProps
                    ? $type->resolve($data)
                    : $type->dataClass()::from($data)->toArray(),
            ];
        }

        return $rendered;
    }
}
