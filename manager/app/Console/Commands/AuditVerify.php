<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:verify')]
#[Description('Verifica a integridade da cadeia de hashes da trilha de auditoria')]
class AuditVerify extends Command
{
    public function handle(AuditVerifier $verifier): int
    {
        $result = $verifier->verify();

        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
