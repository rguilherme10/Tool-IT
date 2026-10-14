<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleKey;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Idempotente: pode rodar a cada deploy; mantém papéis do sistema alinhados ao enum RoleKey. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $ids = [];
        foreach (PermissionEnum::cases() as $case) {
            $ids[$case->value] = Permission::updateOrCreate(['key' => $case->value])->id;
        }

        foreach (RoleKey::cases() as $key) {
            $role = Role::updateOrCreate(
                ['key' => $key->value],
                ['name' => $key->label(), 'is_system' => true],
            );

            $role->permissions()->sync(array_map(fn (PermissionEnum $p) => $ids[$p->value], $key->permissions()));
        }
    }
}
