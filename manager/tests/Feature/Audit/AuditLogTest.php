<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    private function seedLogs(int $n = 3): void
    {
        $logger = app(AuditLogger::class);
        $user = User::factory()->create();
        for ($i = 1; $i <= $n; $i++) {
            $logger->log("test.event.{$i}", $user, $user, metadata: ['i' => $i, 'nested' => ['b' => 2, 'a' => 1]]);
        }
    }

    public function test_chain_is_sequential_and_verifies(): void
    {
        $this->seedLogs(5);

        $this->assertSame([1, 2, 3, 4, 5], DB::table('audit_logs')->orderBy('seq')->pluck('seq')->map(fn ($s) => (int) $s)->all());
        $this->artisan('audit:verify')->expectsOutputToContain('5 registros')->assertSuccessful();
    }

    public function test_empty_chain_verifies(): void
    {
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_update_and_delete_are_blocked_by_database_triggers(): void
    {
        $this->seedLogs(1);

        try {
            DB::table('audit_logs')->update(['action' => 'tampered']);
            $this->fail('UPDATE deveria falhar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('audit_logs')->delete();
            $this->fail('DELETE deveria falhar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_model_refuses_writes(): void
    {
        $this->seedLogs(1);
        $row = AuditLog::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $row->delete();
    }

    public function test_verify_detects_modified_record(): void
    {
        $this->seedLogs(3);
        DB::unprepared('DROP TRIGGER audit_logs_no_update'); // simula atacante com acesso direto ao banco
        DB::table('audit_logs')->where('seq', 2)->update(['action' => 'tampered']);

        $this->artisan('audit:verify')->expectsOutputToContain('seq 2')->assertFailed();
    }

    public function test_verify_detects_deleted_middle_record(): void
    {
        $this->seedLogs(3);
        DB::unprepared('DROP TRIGGER audit_logs_no_delete');

        DB::table('audit_logs')->where('seq', 2)->delete();

        $this->artisan('audit:verify')->expectsOutputToContain('Sequência quebrada')->assertFailed();
    }

    public function test_verify_detects_removed_tail(): void
    {
        $this->seedLogs(3);
        DB::unprepared('DROP TRIGGER audit_logs_no_delete');
        DB::table('audit_logs')->where('seq', 3)->delete();

        $this->artisan('audit:verify')->expectsOutputToContain('cabeçalho')->assertFailed();
    }

    public function test_sensitive_metadata_is_redacted_and_never_stored(): void
    {
        app(AuditLogger::class)->log('test.secret', null, null, metadata: [
            'password' => 'hunter2', 'enrollment_token' => 'tk_abc', 'ok' => 'visible',
            'nested' => ['Authorization' => 'Bearer x'],
        ]);

        $raw = (string) DB::table('audit_logs')->value('metadata');
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('tk_abc', $raw);
        $this->assertStringNotContainsString('Bearer', $raw);
        $this->assertStringContainsString('visible', $raw);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_failure_inside_transaction_rolls_back_the_audit_entry(): void
    {
        try {
            DB::transaction(function () {
                app(AuditLogger::class)->log('test.rolled_back');
                throw new \RuntimeException('falha da ação auditada');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, (int) DB::table('audit_chain')->value('last_seq'));
        $this->artisan('audit:verify')->assertSuccessful();
    }
}
