<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Konventix\Identity\Models\User;
use Konventix\Platform\Database\Seeders\BrandSeeder;
use Konventix\Platform\Database\Seeders\OperatorAdminSeeder;
use Konventix\Platform\Models\Operator;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Capabilities first (ADR-0013): OperatorAdminSeeder grants whatever
        // permission rows exist, so the sync must have produced them.
        Artisan::call('permissions:sync');

        $this->call(BrandSeeder::class);
        $this->call(OperatorAdminSeeder::class);

        // Cross-module composition lives HERE, in the host seeder: modules
        // may not import each other's models (ADR-0004), the host may.
        $this->seedOperatorAdminUser();

        // Idempotent like everything else in this seeder — re-seeding an
        // existing dev database must be safe.
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password', 'username' => 'test-user'],
        );
    }

    /**
     * The dev login holding the Operator Admin role (ADR-0029): assignment
     * happens under the operator's scope id, where the role lives.
     */
    private function seedOperatorAdminUser(): void
    {
        $operator = Operator::query()->firstWhere('name', 'Konventix Dev Operator');

        if ($operator === null) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($operator->getKey());

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@konventix.test'],
            ['name' => 'Operator Admin', 'password' => 'password', 'username' => 'operator-admin'],
        );

        $admin->assignRole('Operator Admin');

        $registrar->setPermissionsTeamId(null);
    }
}
