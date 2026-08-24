<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\FooterLink;

/**
 * @extends Factory<FooterLink>
 */
class FooterLinkFactory extends Factory
{
    /** @var class-string<FooterLink> */
    protected $model = FooterLink::class;

    /**
     * No `operator_id` here: footer entries are operator-owned and nothing
     * stamps one implicitly, so a caller states which operator's footer this
     * belongs to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->words(2, true),
            'kind' => FooterLinkKind::Url,
            'url' => '/pages/'.fake()->slug(),
            'dialog_key' => null,
            'opens_in_new_tab' => false,
            'position' => fake()->numberBetween(0, 20),
        ];
    }

    public function dialog(string $key = 'cookie-settings'): self
    {
        return $this->state(fn (): array => [
            'kind' => FooterLinkKind::Dialog,
            'url' => null,
            'dialog_key' => $key,
        ]);
    }
}
