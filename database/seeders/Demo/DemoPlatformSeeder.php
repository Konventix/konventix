<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\AssignRoleToUser;
use Konventix\Platform\Actions\SaveRole;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Actions\UpdateBrandTheme;
use Konventix\Platform\Data\BrandThemeData;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission;
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
        private readonly SaveRole $saveRole,
        private readonly AssignRoleToUser $assignRole,
        private readonly ModuleGate $gate,
    ) {}

    public function handle(Brand $brand, EventArchetype $archetype): void
    {
        $this->seedTheme($brand);
        $this->seedModuleActivation($brand, $archetype);
    }

    /**
     * Roles are seeded after the users exist, since a role nobody holds
     * tells the console only half the story: each seeded role goes to one
     * distinct member, so the roles screen shows real assignments.
     *
     * @param  Collection<int, User>  $members
     */
    public function seedRoles(Brand $brand, Collection $members): void
    {
        $catalog = Permission::query()->pluck('name');

        /** @var array<string, list<string>> $roles */
        $roles = [
            // Everything the brand's own staff could ever need — the role an
            // operator hands to the person running the brand.
            'Brand Admin' => $catalog->all(),
            // The door: scanning, badges, and reading who registered.
            'Front Desk' => $catalog
                ->filter(fn (string $name): bool => str_starts_with($name, 'admission.')
                    || $name === 'registration.view-registrations')
                ->values()
                ->all(),
            // The newsroom: writing and publishing, moderating comments.
            'Editor' => $catalog
                ->filter(fn (string $name): bool => str_starts_with($name, 'content.'))
                ->values()
                ->all(),
        ];

        $index = 0;

        foreach ($roles as $name => $permissions) {
            $holder = $members->get($index++);

            if ($permissions === []) {
                continue;
            }

            $existing = Role::query()
                ->where('brand_id', $brand->getKey())
                ->where('name', $name)
                ->first();

            $role = $existing ?? $this->saveRole->create($brand->getKey(), $name, $permissions);

            if ($holder instanceof User) {
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
