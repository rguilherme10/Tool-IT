<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32);
            $table->string('name', 120);
            $table->string('severity', 16)->default('warning');
            // Limite que DISPARA a condição (ex.: 90 = 90 %; para agent_offline, segundos sem comunicação).
            $table->decimal('threshold', 10, 2);
            // Histerese: a condição só "limpa" abaixo deste valor (NULL = calculado pelo avaliador).
            $table->decimal('clear_threshold', 10, 2)->nullable();
            // Persistência: a condição precisa durar ao menos isto antes de abrir o alerta (0 = leitura atual).
            $table->unsignedInteger('min_duration_seconds')->default(0);
            // E precisa ficar limpa por isto antes de resolver (evita abrir/fechar a cada oscilação).
            $table->unsignedInteger('clear_duration_seconds')->default(120);
            // Enquanto aberto e não reconhecido, registra uma "repetição" a cada intervalo (0 = nunca).
            $table->unsignedInteger('repeat_interval_seconds')->default(0);
            $table->json('params')->nullable();           // ex.: {"min_version":"0.2.0"}
            // NULL = todos os computadores; senão o grupo e seus descendentes.
            $table->foreignId('endpoint_group_id')->nullable()->constrained('endpoint_groups')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
