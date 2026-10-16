<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('params');
            $table->string('risk', 8);                      // low | medium | high
            $table->string('state', 20);                    // ver App\Enums\TaskState
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('cancel_requested_at')->nullable();
            $table->json('result_summary')->nullable();     // status, exit_code, duração
            $table->timestamps();

            $table->index(['endpoint_id', 'state']);
            $table->index(['state', 'expires_at']);
            $table->unique(['created_by', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_tasks');
    }
};
