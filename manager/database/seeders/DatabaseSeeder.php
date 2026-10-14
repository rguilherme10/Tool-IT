<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Somente dados de referência (papéis e permissões). Nenhum usuário ou dado fictício:
 * o primeiro administrador é criado com `php artisan toolit:create-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
