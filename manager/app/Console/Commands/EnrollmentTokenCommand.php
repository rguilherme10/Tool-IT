<?php

namespace App\Console\Commands;

use App\Models\EndpointGroup;
use App\Models\User;
use App\Services\Agents\EnrollmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('toolit:enrollment-token {--user= : E-mail do operador que emite o token} {--group= : UUID do grupo} {--hours=24} {--uses=1}')]
#[Description('Emite um token de enrollment (equivalente à tela “Novo agente”); o token é exibido uma única vez')]
class EnrollmentTokenCommand extends Command
{
    public function handle(EnrollmentService $enrollment): int
    {
        $actor = User::where('email', mb_strtolower((string) $this->option('user')))->where('is_active', true)->first();
        if ($actor === null) {
            $this->error('Informe --user com o e-mail de um usuário ativo.');

            return self::FAILURE;
        }

        $group = null;
        if ($this->option('group')) {
            $group = EndpointGroup::where('uuid', $this->option('group'))->first();
            if ($group === null) {
                $this->error('Grupo não encontrado.');

                return self::FAILURE;
            }
        }

        try {
            [$token, $plain] = $enrollment->issueToken($actor, $group, (int) $this->option('uses'), (int) $this->option('hours'));
        } catch (AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($plain);
        $this->comment("Expira em {$token->expires_at->toDateTimeString()} · usos: {$token->max_uses}. Não será exibido novamente.");

        return self::SUCCESS;
    }
}
