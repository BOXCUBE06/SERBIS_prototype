<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drivers, authorized passengers and patient relatives. The paper form shows
// two blank slots for each role, but a fixed driver_1/driver_2 pair of
// columns caps the record at exactly what the paper allows — a child row per
// person scales past that without a schema change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_conduction_request_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conduction_request_id')
                ->constrained('tbl_conduction_requests', 'conduction_request_id')
                ->cascadeOnDelete();
            $table->enum('role', ['driver', 'passenger', 'relative']);
            $table->string('name');
            // Preserves the order names were entered in — "Driver 1" vs "Driver
            // 2" on the form is a display distinction, not a data one, but the
            // form still has to render them back in the order they were typed.
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_conduction_request_people');
    }
};
