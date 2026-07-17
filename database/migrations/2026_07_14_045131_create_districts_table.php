<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('osm_id')->unique();
            $table->string('name');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE districts ADD COLUMN geom geometry(Geometry, 4326) NOT NULL');
        DB::statement('CREATE INDEX districts_geom_gist ON districts USING GIST (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
