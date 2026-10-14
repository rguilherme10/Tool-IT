<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            // NULL = escopo global; caso contrário, o grupo (e seus descendentes).
            $table->foreignId('endpoint_group_id')->nullable()->constrained('endpoint_groups')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // Coluna gerada: UNIQUE com NULL permitiria duplicar atribuições globais.
            $table->unsignedBigInteger('scope_key')->virtualAs('COALESCE(endpoint_group_id, 0)');
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
