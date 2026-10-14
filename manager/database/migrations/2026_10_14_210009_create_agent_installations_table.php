<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_installations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->text('public_key');
            $table->string('key_fingerprint', 64)->unique();
            $table->text('previous_public_key')->nullable();
            $table->timestamp('previous_key_valid_until')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('agent_version', 32)->nullable();
            $table->string('machine_fingerprint', 64)->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            // No máximo UMA instalação ativa por endpoint (UNIQUE ignora NULL).
            $table->unsignedBigInteger('active_endpoint_id')
                ->virtualAs("CASE WHEN status = 'active' THEN endpoint_id END")
                ->nullable();
            $table->timestamps();

            $table->unique('active_endpoint_id');
            $table->index(['endpoint_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_installations');
    }
};
