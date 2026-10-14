<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Hostname e MAC NÃO são identidade: sem UNIQUE (ver docs/architecture.md §4).
            $table->string('hostname', 255)->index();
            $table->foreignId('endpoint_group_id')->nullable()->constrained('endpoint_groups')->nullOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->string('os_name', 120)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('os_build', 32)->nullable();
            $table->string('os_arch', 16)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('domain', 255)->nullable();
            $table->string('current_user', 255)->nullable();
            $table->json('ip_addresses')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_inventory_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['endpoint_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoints');
    }
};
