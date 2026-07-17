<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')
                ->references('id')
                ->on('geocoding_sessions')
                ->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()
                ->constrained('districts')
                ->nullOnDelete();
            $table->string('raw_address');
            $table->string('normalized_address');
            $table->enum('status', ['pending', 'geocoded', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->double('nominatim_lat')->nullable();
            $table->double('nominatim_lon')->nullable();
            $table->double('photon_lat')->nullable();
            $table->double('photon_lon')->nullable();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->boolean('is_problem')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE addresses ADD COLUMN nominatim_geom geometry(Point, 4326)');
        DB::statement('ALTER TABLE addresses ADD COLUMN photon_geom geometry(Point, 4326)');
        DB::statement('CREATE INDEX addresses_nominatim_geom_gist ON addresses USING GIST (nominatim_geom)');
        DB::statement('CREATE INDEX addresses_photon_geom_gist ON addresses USING GIST (photon_geom)');
        DB::statement('CREATE INDEX addresses_session_id_idx ON addresses (session_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
