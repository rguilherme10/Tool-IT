<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

/** Verificação somente leitura da cadeia de hashes (usada por `audit:verify` e pela tela de auditoria). */
class AuditVerifier
{
    /** @return array{ok: bool, count: int, message: string} */
    public function verify(): array
    {
        $previous = str_repeat('0', 64);
        $expectedSeq = 1;
        $count = 0;

        foreach (DB::table('audit_logs')->orderBy('seq')->cursor() as $row) {
            $row = (array) $row;

            if ((int) $row['seq'] !== $expectedSeq) {
                return $this->fail($count, "Sequência quebrada: esperado {$expectedSeq}, encontrado {$row['seq']} (registro removido?).");
            }
            if ($row['prev_hash'] !== $previous) {
                return $this->fail($count, "prev_hash inválido em seq {$row['seq']}.");
            }
            if (! hash_equals(AuditLogger::computeHash($row), $row['hash'])) {
                return $this->fail($count, "Hash inválido em seq {$row['seq']} (registro alterado).");
            }

            $previous = $row['hash'];
            $expectedSeq++;
            $count++;
        }

        $head = DB::table('audit_chain')->where('id', 1)->first();
        if ((int) $head->last_seq !== $count || ! hash_equals($head->last_hash, $previous)) {
            return $this->fail($count, 'O cabeçalho da cadeia não corresponde ao último registro (cauda removida?).');
        }

        return ['ok' => true, 'count' => $count, 'message' => "Cadeia íntegra: {$count} registros verificados."];
    }

    /** @return array{ok: bool, count: int, message: string} */
    private function fail(int $verified, string $message): array
    {
        return ['ok' => false, 'count' => $verified, 'message' => $message];
    }
}
