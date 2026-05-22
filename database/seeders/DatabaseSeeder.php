<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

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
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Super Admin role and assign all permissions
        $superAdminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdminRole->syncPermissions(\Spatie\Permission\Models\Permission::all());

        $developerRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Developer']);
        $developerRole->syncPermissions(['gratitude:import']);

        $user = User::firstOrCreate(
            ['email' => 'it@artinvoyage.com'],
            [
                'first_name' => 'IT',
                'last_name' => 'AIv',
                'name' => 'IT AIv',
                'password' => \Illuminate\Support\Facades\Hash::make('qwertyuiop@2026gratitude'),
                'status' => 'active',
            ]
        );

        $user->assignRole($superAdminRole);
        $user->assignRole($developerRole);

        $this->call(GratitudeLevelSeeder::class);
    }
}
