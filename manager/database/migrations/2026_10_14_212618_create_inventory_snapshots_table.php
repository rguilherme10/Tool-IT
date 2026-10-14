<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Só metadados do envio (idempotência e histórico de mudanças). O payload em si não é guardado.
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('snapshot_id')->unique();
            $table->timestamp('collected_at');
            $table->timestamp('received_at');
            $table->json('section_status');
            $table->json('changes')->nullable();   // ex.: {"software_added":3,"software_removed":1}
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->index(['endpoint_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');
    }
};
