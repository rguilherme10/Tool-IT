<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_task_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('remote_task_id')->constrained('remote_tasks')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestamp('claimed_at');
            $table->timestamp('lease_expires_at');
            $table->timestamp('started_at')->nullable();    // informado pelo Agent (informativo)
            $table->timestamp('finished_at')->nullable();   // informado pelo Agent (informativo)
            $table->string('status', 16)->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('stdout')->nullable();             // ≤ 16 KiB, sanitizado na ingestão
            $table->text('stderr')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['remote_task_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_task_attempts');
    }
};
