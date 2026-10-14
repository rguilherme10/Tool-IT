<?php

namespace App\Console\Commands;

use App\Enums\RoleKey;
use App\Services\Users\UserService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('toolit:create-admin {--name= : Nome} {--email= : E-mail}')]
#[Description('Cria um administrador global (senha via prompt ou variável TOOLIT_ADMIN_PASSWORD)')]
class CreateAdmin extends Command
{
    public function handle(UserService $users): int
    {
        $name = $this->option('name') ?: $this->ask('Nome');
        $email = $this->option('email') ?: $this->ask('E-mail');
        // Nunca por argumento de linha de comando (apareceria na lista de processos).
        $password = getenv('TOOLIT_ADMIN_PASSWORD') ?: $this->secret('Senha (mín. 12 caracteres)');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $users->create($name, $email, $password);
        $users->assignRole($user, RoleKey::Admin);

        $this->info("Administrador criado: {$user->email}");
        $this->line('Ative o MFA (TOTP) no primeiro acesso — é obrigatório para administradores.');

        return self::SUCCESS;
    }
}
