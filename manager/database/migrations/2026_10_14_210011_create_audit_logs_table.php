<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: sem updated_at; triggers (migration seguinte) bloqueiam UPDATE/DELETE.
        // Sem FKs de propósito: apagar usuário/endpoint não pode tocar a trilha.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->unique();
            $table->timestamp('occurred_at', 6);
            $table->string('actor_type', 32)->nullable();   // user | agent | system
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 96);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->unsignedBigInteger('endpoint_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->index('occurred_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
            $table->index('endpoint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
