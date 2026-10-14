<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            // sha256(nome|versão|arch|origem): unicidade segura com campos opcionais (NULL não é único em UNIQUE).
            $table->char('identity_hash', 64);
            $table->string('name', 255);
            $table->string('version', 64)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->date('install_date')->nullable();
            $table->string('arch', 8)->nullable();
            $table->string('source', 16);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable();

            $table->unique(['endpoint_id', 'identity_hash']);
            $table->index(['endpoint_id', 'name']);
            $table->index(['endpoint_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_items');
    }
};
