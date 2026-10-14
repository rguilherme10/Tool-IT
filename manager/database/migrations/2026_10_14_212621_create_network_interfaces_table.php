<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('description', 255)->nullable();
            $table->string('type', 32)->nullable();
            $table->string('mac', 17)->nullable();
            $table->json('ips')->nullable();
            $table->boolean('up')->default(false);
            $table->unsignedBigInteger('speed_mbps')->nullable();
            $table->timestamps();

            $table->index('endpoint_id');
            $table->index('mac');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_interfaces');
    }
};
