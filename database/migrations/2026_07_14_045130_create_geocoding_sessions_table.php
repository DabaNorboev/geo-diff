<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geocoding_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedSmallInteger('total_addresses')->default(0);
            $table->unsignedSmallInteger('processed_addresses')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedSmallInteger('threshold_meters')->default(50);
            $table->decimal('avg_distance_meters', 10, 2)->nullable();
            $table->decimal('problem_rate', 5, 4)->nullable();
            $table->float('moran_i')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocoding_sessions');
    }
};
