<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linha única, bloqueada (FOR UPDATE) a cada gravação de auditoria.
        Schema::create('audit_chain', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->char('last_hash', 64);
        });

        DB::table('audit_chain')->insert([
            'id' => 1,
            'last_seq' => 0,
            'last_hash' => str_repeat('0', 64),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain');
    }
};
