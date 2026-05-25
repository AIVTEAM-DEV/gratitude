<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create initial permissions
        $permissions = [
            'user:create',
            'user:update',
            'user:delete',
            'role:create',
            'role:update',
            'role:delete',
            'gratitude:view',
            'gratitude:create',
            'gratitude:update',
            'gratitude:delete',
            'gratitude:import',
            'gratitude.earned:create',
            'gratitude.earned:update',
            'gratitude.earned:delete',
            'gratitude.bonus:create',
            'gratitude.bonus:update',
            'gratitude.bonus:delete',
            'gratitude.cancel:create',
            'gratitude.cancel:delete',
            'gratitude.redeem:create',
            'gratitude.redeem:update',
            'gratitude.redeem:delete',
            'application-key:create',
            'application-key:update',
            'application-key:delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Super Admin role and assign all permissions
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdminRole->syncPermissions(Permission::all());

        $developerRole = Role::firstOrCreate(['name' => 'Developer']);
        $developerRole->syncPermissions(['gratitude:import']);

        $seededUserEmail = env('SEEDED_USER_EMAIL');
        $seededUserPassword = env('SEEDED_USER_PASSWORD');
        $seededUserFirstName = env('SEEDED_USER_FIRST_NAME', 'IT');
        $seededUserLastName = env('SEEDED_USER_LAST_NAME', 'AIv');
        $seededUserName = env('SEEDED_USER_NAME', 'IT AIv');

        throw_if(
            blank($seededUserEmail) || blank($seededUserPassword),
            \RuntimeException::class,
            'SEEDED_USER_EMAIL and SEEDED_USER_PASSWORD must be set before seeding users.'
        );

        $user = User::firstOrCreate(
            ['email' => $seededUserEmail],
            [
                'first_name' => $seededUserFirstName,
                'last_name' => $seededUserLastName,
                'name' => $seededUserName,
                'password' => Hash::make($seededUserPassword),
                'status' => 'active',
            ]
        );

        $user->assignRole($superAdminRole);
        $user->assignRole($developerRole);

        $this->call(GratitudeLevelSeeder::class);
    }
}
