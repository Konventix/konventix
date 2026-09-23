<?php

declare(strict_types=1);

use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Providers\CompetitionServiceProvider;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * What the module *is* to a brand (design §11, §13, ADR-0003, ADR-0045): the
 * capabilities and the two default roles, and, further down, what a brand
 * with `competition` switched off sees of it on its own host and on the
 * operator console.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */
function activationTestRole(Brand $brand, string $key): ?Role
{
    return Role::query()->where('brand_id', $brand->getKey())->where('default_key', $key)->first();
}

/**
 * @return list<string>
 */
function activationTestGrants(?Role $role): array
{
    return $role?->permissions->pluck('name')->sort()->values()->all() ?? [];
}

describe('capabilities and roles (§11)', function (): void {
    it('declares the nine capabilities §11 counts, the three §11 adds among them', function (): void {
        expect(array_column(Permission::cases(), 'value'))->toBe([
            'competition.view-competitions',
            'competition.manage-disciplines',
            'competition.manage-competitions',
            'competition.manage-entrants',
            'competition.check-in-entrants',
            'competition.manage-schedule',
            'competition.record-results',
            'competition.correct-results',
            'competition.view-entrant-contacts',
        ]);
    });

    it('lets permissions:sync pick the three new capabilities up', function (): void {
        $this->artisan('permissions:sync', ['--no-roles' => true])->assertSuccessful();

        $synced = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->all();

        expect($synced)->toContain('competition.manage-schedule')
            ->and($synced)->toContain('competition.check-in-entrants')
            ->and($synced)->toContain('competition.view-entrant-contacts');
    });

    it('ships Tournament Admin with all nine and Tournament Desk with the day-of four', function (): void {
        $roles = (new CompetitionServiceProvider(app()))->defaultRoles();

        expect($roles)->toHaveCount(2)
            ->and($roles[0]->key)->toBe('competition.tournament-admin')
            ->and($roles[0]->name)->toBe('Tournament Admin')
            ->and($roles[0]->permissions)->toBe(array_column(Permission::cases(), 'value'))
            ->and($roles[1]->key)->toBe('competition.tournament-desk')
            ->and($roles[1]->name)->toBe('Tournament Desk')
            // §11's table, in the order the provider lists them.
            ->and($roles[1]->permissions)->toBe([
                'competition.view-competitions',
                'competition.check-in-entrants',
                'competition.record-results',
                'competition.manage-schedule',
            ]);
    });

    it('keeps corrections and roster changes away from the desk (#135)', function (): void {
        $desk = (new CompetitionServiceProvider(app()))->defaultRoles()[1];

        expect($desk->permissions)->not->toContain(Permission::CorrectResults->value)
            ->and($desk->permissions)->not->toContain(Permission::ManageEntrants->value)
            ->and($desk->permissions)->not->toContain(Permission::ViewEntrantContacts->value);
    });

    it('materializes both roles when a brand activates the module, and neither before', function (): void {
        $brand = Brand::factory()->create(['slug' => 'activation-roles']);

        expect(activationTestRole($brand, 'competition.tournament-admin'))->toBeNull()
            ->and(activationTestRole($brand, 'competition.tournament-desk'))->toBeNull();

        app(UpdateBrandModules::class)->handle($brand, ['competition' => true]);

        // Sorted by name, because the relation reads without an ORDER BY.
        expect(activationTestGrants(activationTestRole($brand, 'competition.tournament-admin')))->toBe([
            'competition.check-in-entrants',
            'competition.correct-results',
            'competition.manage-competitions',
            'competition.manage-disciplines',
            'competition.manage-entrants',
            'competition.manage-schedule',
            'competition.record-results',
            'competition.view-competitions',
            'competition.view-entrant-contacts',
        ])
            ->and(activationTestGrants(activationTestRole($brand, 'competition.tournament-desk')))->toBe([
                'competition.check-in-entrants',
                'competition.manage-schedule',
                'competition.record-results',
                'competition.view-competitions',
            ]);
    });
});
