<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            // A regra pode ser apagada: o alerta guarda um retrato do que a disparou.
            $table->foreignId('alert_rule_id')->nullable()->constrained('alert_rules')->nullOnDelete();
            $table->string('rule_type', 32);
            $table->string('rule_name', 120);
            $table->string('subject', 64)->nullable();     // ex.: "C:" (volume)
            // sha256(regra|endpoint|assunto): deduplicação — no máximo UM alerta ativo por chave.
            $table->char('dedupe_hash', 64);
            $table->char('active_dedupe', 64)->nullable()
                ->virtualAs("CASE WHEN status <> 'resolved' THEN dedupe_hash END");
            $table->string('status', 16)->default('open');
            $table->string('severity', 16);
            $table->string('title', 200);
            $table->json('details')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_repeated_at')->nullable();
            $table->unsignedInteger('repeat_count')->default(0);
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_reason', 32)->nullable();   // auto | endpoint_inactive | rule_disabled | rule_removed
            $table->timestamps();

            $table->unique('active_dedupe');
            $table->index(['status', 'severity']);
            $table->index(['endpoint_id', 'status']);
            $table->index('opened_at');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
