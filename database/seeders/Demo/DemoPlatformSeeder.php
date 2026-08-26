<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\AssignRoleToUser;
use Konventix\Platform\Actions\MaterializeDefaultRoles;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Actions\UpdateBrandTheme;
use Konventix\Platform\Data\BrandThemeData;
use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterColumn;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;

/**
 * Everything the Platform kernel itself owns for a demo brand: design
 * tokens, per-brand module activation, the brand's own roles with real
 * capability grants, and the operator admin's console favorites.
 *
 * Module activation is seeded, not assumed (ADR-0003): the archetype says
 * which gateable modules a brand runs, and the placeholder modules stay off
 * — which is also what makes the gate demonstrable, since the brand page
 * then shows both states, and the two seeded brands do not agree on them.
 *
 * Brand storage settings are deliberately NOT seeded. `Storage\DiskResolver`
 * routes a brand's media to its configured bucket, so a seeded S3/Garage
 * endpoint that nobody is running would break every seeded image; the
 * storage console lists every brand as "not configured" regardless, so the
 * screen is not empty either way.
 */
final class DemoPlatformSeeder
{
    /**
     * Accent colours per brand slug, so the seeded brands are visually
     * distinct the moment you switch domains. Values are checked against the
     * BFSG contrast minimum by UpdateBrandTheme — these pass against the
     * catalog's default `on-accent`.
     *
     * @var array<string, string>
     */
    private const array ACCENTS = [
        'sxlan' => 'hsl(221 83% 40%)',
        'xpertise' => 'hsl(292 76% 35%)',
        'nordcon' => 'hsl(160 84% 24%)',
        'playa' => 'hsl(21 90% 35%)',
        'brickfest' => 'hsl(239 84% 50%)',
        'synthwave' => 'hsl(199 89% 32%)',
        'quizzity' => 'hsl(271 81% 42%)',
        'meeple' => 'hsl(340 82% 39%)',
    ];

    public function __construct(
        private readonly UpdateBrandTheme $updateTheme,
        private readonly UpdateBrandModules $updateModules,
        private readonly AssignRoleToUser $assignRole,
        private readonly ModuleGate $gate,
        private readonly MaterializeDefaultRoles $materialize,
    ) {}

    public function handle(Brand $brand, EventArchetype $archetype): void
    {
        $this->seedTheme($brand);
        $this->seedModuleActivation($brand, $archetype);
    }

    /**
     * Roles are the modules' defaults (ADR-0045), materialized here rather
     * than hardcoded, then handed to one distinct member each so the roles
     * screen shows real assignments.
     *
     * @param  Collection<int, User>  $members
     */
    public function seedRoles(Brand $brand, Collection $members): void
    {
        $this->materialize->handle($brand);

        foreach (['Brand Admin', 'Registration Desk', 'Content Editor'] as $index => $name) {
            $holder = $members->get($index);
            $role = Role::query()->where('brand_id', $brand->getKey())->where('name', $name)->first();

            if ($role !== null && $holder instanceof User) {
                $this->assignRole->handle($role, $holder->getKey());
            }
        }
    }

    /**
     * The operator admin's pinned shortcuts (ADR-0042). Not brand-owned —
     * the console spans brands — so this runs once per seed, not per brand.
     */
    public function seedFavorites(?User $admin): void
    {
        if ($admin === null) {
            return;
        }

        $favorites = [
            ['label' => 'Registrations', 'url' => '/admin/registrations', 'position' => 1],
            ['label' => 'Orders', 'url' => '/admin/orders', 'position' => 2],
            ['label' => 'Media', 'url' => '/admin/media', 'position' => 3],
        ];

        foreach ($favorites as $favorite) {
            AdminFavorite::query()->firstOrCreate(
                ['user_id' => $admin->getKey(), 'url' => $favorite['url']],
                ['label' => $favorite['label'], 'position' => $favorite['position']],
            );
        }
    }

    /**
     * The operator's demo footer: a couple of legal links, and two columns
     * built from blocks so the block pipeline has something real to render
     * on every seeded brand's pages. Not brand-owned — one operator-wide
     * footer for every brand it runs (ADR-0001) — so this runs once per
     * seed, like `seedFavorites()`.
     */
    public function seedFooter(Operator $operator): void
    {
        if (FooterLink::query()->where('operator_id', $operator->getKey())->exists()) {
            return;
        }

        $this->createFooterLink($operator, [
            'label' => 'Imprint',
            'kind' => FooterLinkKind::Url,
            'url' => '/pages/imprint',
            'position' => 0,
        ]);

        $this->createFooterLink($operator, [
            'label' => 'Privacy',
            'kind' => FooterLinkKind::Url,
            'url' => '/pages/privacy',
            'position' => 1,
        ]);

        $this->createFooterLink($operator, [
            'label' => 'Cookie settings',
            'kind' => FooterLinkKind::Dialog,
            'dialog_key' => 'cookie-settings',
            'position' => 2,
        ]);

        $address = collect([
            $operator->address_line,
            trim(sprintf('%s %s', $operator->postal_code, $operator->city)),
        ])->filter(fn (?string $line): bool => $line !== null && trim($line) !== '')->implode("\n");

        $this->createFooterColumn($operator, [
            'heading' => 'Contact',
            'blocks' => [['type' => 'text', 'data' => ['heading' => null, 'body' => $address]]],
            'position' => 0,
        ]);

        $this->createFooterColumn($operator, [
            'heading' => 'Follow us',
            'blocks' => [['type' => 'social-links', 'data' => ['links' => [
                ['network' => 'github', 'url' => 'https://github.com/konventix', 'label' => null],
            ]]]],
            'position' => 1,
        ]);
    }

    /**
     * `operator_id` is stamped directly rather than mass-assigned — it is
     * deliberately not fillable, the same guard `UpdateFooterLinks` relies
     * on to keep it out of a submitted form.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createFooterLink(Operator $operator, array $attributes): void
    {
        $link = new FooterLink($attributes);
        $link->operator_id = $operator->getKey();
        $link->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createFooterColumn(Operator $operator, array $attributes): void
    {
        $column = new FooterColumn($attributes);
        $column->operator_id = $operator->getKey();
        $column->save();
    }

    private function seedTheme(Brand $brand): void
    {
        // Idempotent: an operator who re-themed a demo brand by hand keeps
        // their tokens on the next run.
        if ($brand->theme_tokens !== []) {
            return;
        }

        $accent = self::ACCENTS[$brand->slug] ?? null;

        if ($accent === null) {
            return;
        }

        $this->updateTheme->handle($brand, new BrandThemeData(tokens: [
            'accent' => $accent,
            'on-accent' => 'hsl(0 0% 100%)',
        ]));
    }

    /**
     * Which gateable modules are on is the archetype's call: a trade fair
     * runs no tournaments, so Competition stays off for it and every
     * listener behind that gate short-circuits (ADR-0003). Modules without a
     * domain behind them are off everywhere.
     */
    private function seedModuleActivation(Brand $brand, EventArchetype $archetype): void
    {
        $gateable = $this->gate->gateableModuleNames();
        $active = $archetype->activeModules();

        $this->updateModules->handle($brand, array_combine(
            $gateable,
            array_map(
                fn (string $module): bool => in_array($module, $active, true),
                $gateable,
            ),
        ));
    }
}
