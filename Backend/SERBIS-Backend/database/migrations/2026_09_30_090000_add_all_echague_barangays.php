<?php

use App\Services\BarangaySync;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adds the rest of Echague's 64 barangays so production gets them on deploy.
     * BarangaySync matches existing rows by PSGC code, then by name, so the
     * existing barangays keep their ids; missing ones are inserted with codes.
     */
    public function up(): void
    {
        app(BarangaySync::class)->apply();
    }

    /** No-op: residents and SMS logs may already point at the added rows. */
    public function down(): void
    {
    }
};
