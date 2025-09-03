<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SysAdmin\RoleModel;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'urole_name' => 'Super Admin',
                'type' => 'super_admin',
                'description' => 'Full system access with all permissions',
                'is_active' => 1,
                'is_default' => 0,
                'company_id' => 1,
                'created_by' => 1
            ],
            [
                'urole_name' => 'Admin',
                'type' => 'admin',
                'description' => 'Administrative access with most permissions',
                'is_active' => 1,
                'is_default' => 0,
                'company_id' => 1,
                'created_by' => 1
            ],
            [
                'urole_name' => 'Branch User',
                'type' => 'user',
                'description' => 'Standard user access with limited permissions',
                'is_active' => 1,
                'is_default' => 1,
                'company_id' => 1,
                'created_by' => 1
            ],
            [
                'urole_name' => 'Manager',
                'type' => 'manager',
                'description' => 'Management level access',
                'is_active' => 1,
                'is_default' => 0,
                'company_id' => 1,
                'created_by' => 1
            ]
        ];

        foreach ($roles as $role) {
            RoleModel::updateOrCreate(
                ['urole_name' => $role['urole_name']],
                $role
            );
        }
    }
}
