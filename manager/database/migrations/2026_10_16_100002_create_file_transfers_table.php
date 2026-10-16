<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('remote_task_id')->nullable()->constrained('remote_tasks')->nullOnDelete();
            $table->string('direction', 8);                  // send | fetch
            $table->foreignId('created_by')->constrained('users');
            $table->string('original_name', 255);            // só metadado (escapado na saída); nunca vira caminho
            $table->string('stored_name', 36);               // UUID gerado pelo servidor
            $table->string('remote_path', 300);              // envio: destino; recuperação: origem (<= 260 UTF-16)
            $table->unsignedBigInteger('size')->nullable();  // recuperação: fixado no begin
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('chunks_total')->nullable();
            $table->unsignedInteger('chunks_received')->default(0);
            $table->boolean('overwrite')->default(false);
            $table->string('status', 16);                    // ver App\Enums\TransferStatus
            $table->string('error', 255)->nullable();
            $table->timestamp('expires_at')->nullable();     // prazo do upload pelo navegador
            $table->timestamp('purged_at')->nullable();      // blobs apagados pela retenção (metadados ficam)
            $table->timestamps();

            $table->index(['endpoint_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('remote_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_transfers');
    }
};
