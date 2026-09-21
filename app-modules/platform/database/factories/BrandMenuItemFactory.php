<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Models\StaticPage;

/**
 * @extends Factory<BrandMenuItem>
 */
class BrandMenuItemFactory extends Factory
{
    /** @var class-string<BrandMenuItem> */
    protected $model = BrandMenuItem::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context, and a test that forgets to establish one
     * should fail loudly rather than silently inventing a brand.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'kind' => BrandMenuItemKind::Url,
            'label' => fake()->words(2, true),
            'static_page_id' => null,
            'url' => 'https://'.fake()->domainName(),
            'opens_in_new_tab' => false,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    public function forPage(StaticPage $page, ?string $label = null): self
    {
        return $this->state(fn (): array => [
            'kind' => BrandMenuItemKind::Page,
            'label' => $label,
            'static_page_id' => $page->getKey(),
            'url' => null,
        ]);
    }

    public function parent(string $label = 'More'): self
    {
        return $this->state(fn (): array => [
            'kind' => BrandMenuItemKind::Parent,
            'label' => $label,
            'static_page_id' => null,
            'url' => null,
        ]);
    }

    public function under(BrandMenuItem $parent): self
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }
}
