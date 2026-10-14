<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('audit:verify')]
#[Description('Verifica a integridade da cadeia de hashes da trilha de auditoria')]
class AuditVerify extends Command
{
    public function handle(): int
    {
        $prev = str_repeat('0', 64);
        $expectedSeq = 1;
        $count = 0;

        $rows = DB::table('audit_logs')->orderBy('seq')->cursor();
        foreach ($rows as $row) {
            $row = (array) $row;

            if ((int) $row['seq'] !== $expectedSeq) {
                return $this->broken("Sequência quebrada: esperado {$expectedSeq}, encontrado {$row['seq']} (registro removido?).");
            }
            if ($row['prev_hash'] !== $prev) {
                return $this->broken("prev_hash inválido em seq {$row['seq']}.");
            }
            if (! hash_equals(AuditLogger::computeHash($row), $row['hash'])) {
                return $this->broken("Hash inválido em seq {$row['seq']} (registro alterado).");
            }

            $prev = $row['hash'];
            $expectedSeq++;
            $count++;
        }

        $head = DB::table('audit_chain')->where('id', 1)->first();
        if ((int) $head->last_seq !== $count || ! hash_equals($head->last_hash, $prev)) {
            return $this->broken('O cabeçalho da cadeia não corresponde ao último registro (cauda removida?).');
        }

        $this->info("Cadeia íntegra: {$count} registros verificados.");

        return self::SUCCESS;
    }

    private function broken(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
