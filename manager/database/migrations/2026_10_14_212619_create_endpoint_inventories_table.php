<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado ATUAL (1:1 com o endpoint). Cada seção guarda o último dado coletado com sucesso e o estado da última coleta.
        Schema::create('endpoint_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('system')->nullable();
            $table->json('hardware')->nullable();
            $table->json('peripherals')->nullable();
            $table->json('section_status');          // {"hardware":{"status":"ok","reason":null,"at":"..."}, ...}
            $table->uuid('last_snapshot_id')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_inventories');
    }
};
