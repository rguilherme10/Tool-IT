<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MESSAGE = 'audit_logs is append-only';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (['UPDATE' => 'update', 'DELETE' => 'delete'] as $event => $suffix) {
            $name = "audit_logs_no_{$suffix}";

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared(
                    "CREATE TRIGGER {$name} BEFORE {$event} ON audit_logs FOR EACH ROW "
                    .'SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \''.self::MESSAGE.'\''
                );
            } elseif ($driver === 'sqlite') {
                DB::unprepared(
                    "CREATE TRIGGER {$name} BEFORE {$event} ON audit_logs BEGIN "
                    .'SELECT RAISE(ABORT, \''.self::MESSAGE.'\'); END'
                );
            } else {
                throw new RuntimeException("Driver de banco não suportado para a trilha de auditoria: {$driver}");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
