<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_session_stats', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')
                ->references('id')
                ->on('geocoding_sessions')
                ->cascadeOnDelete();
            $table->foreignId('district_id')
                ->constrained('districts')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('address_count');
            $table->decimal('avg_distance_meters', 10, 2);
            $table->decimal('problem_rate', 5, 4);
            $table->timestamps();

            $table->unique(['session_id', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('district_session_stats');
    }
};
