<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alto volume (100 endpoints × 1/min ≈ 144 mil linhas/dia): sem created_at/updated_at; retenção por `metrics:prune`.
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('sample_id');
            $table->timestamp('sampled_at');
            $table->timestamp('received_at');
            $table->decimal('cpu_percent', 4, 1)->nullable();
            $table->unsignedInteger('mem_total_mb');
            $table->unsignedInteger('mem_available_mb');
            $table->unsignedBigInteger('uptime_seconds');
            $table->json('volumes');
            $table->decimal('temperature_c', 4, 1)->nullable();

            $table->unique(['endpoint_id', 'sample_id']);
            $table->index(['endpoint_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
