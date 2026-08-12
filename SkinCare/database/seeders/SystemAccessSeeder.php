<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class SystemAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissionNames = [
            Permissions::ADMIN_DASHBOARD_VIEW => 'View admin dashboard',
            Permissions::CATALOG_READ => 'Read catalog management data',
            Permissions::CATALOG_WRITE => 'Create and edit catalog data',
            Permissions::INVENTORY_READ => 'Read inventory data',
            Permissions::INVENTORY_WRITE => 'Adjust inventory',
            Permissions::ORDERS_READ => 'Read orders',
            Permissions::ORDERS_WRITE => 'Update order workflow',
            Permissions::CUSTOMERS_READ => 'Read customer management data',
            Permissions::DISCOUNTS_READ => 'Read discounts',
            Permissions::DISCOUNTS_WRITE => 'Create and edit discounts',
        ];

        foreach ($permissionNames as $slug => $name) {
            Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        $roles = [
            'super-admin' => ['Super Admin', Permissions::all()],
            'admin' => ['Admin', Permissions::all()],
            'catalog-manager' => ['Catalog Manager', [
                Permissions::CATALOG_READ,
                Permissions::CATALOG_WRITE,
                Permissions::INVENTORY_READ,
            ]],
            'order-manager' => ['Order Manager', [
                Permissions::ORDERS_READ,
                Permissions::ORDERS_WRITE,
                Permissions::CUSTOMERS_READ,
                Permissions::INVENTORY_READ,
            ]],
            'support' => ['Support', [
                Permissions::ORDERS_READ,
                Permissions::CUSTOMERS_READ,
            ]],
        ];

        foreach ($roles as $slug => [$name, $permissions]) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_system' => true],
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $permissions)
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
