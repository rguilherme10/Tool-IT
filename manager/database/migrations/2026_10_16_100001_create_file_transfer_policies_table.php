<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_transfer_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nulo = padrão global. Apagar o grupo apaga a política (nullOnDelete a transformaria em global, ampliando o acesso).
            $table->foreignId('endpoint_group_id')->nullable()->constrained('endpoint_groups')->cascadeOnDelete();
            // "global" ou "group:<id>": garante uma única política por escopo (índice único não trata NULL como igual).
            $table->string('scope_key', 40)->unique();
            $table->json('send_dirs');
            $table->json('fetch_dirs');
            $table->json('allowed_extensions');
            $table->unsignedBigInteger('max_bytes')->default(104857600);
            $table->boolean('allow_overwrite')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_transfer_policies');
    }
};
