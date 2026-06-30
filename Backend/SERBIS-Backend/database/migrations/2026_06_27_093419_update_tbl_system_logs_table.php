<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tbl_system_logs', function (Blueprint $table) {
            $table->dropColumn('description');
            
            $table->string('auditable_type')->after('action_type');
            $table->unsignedBigInteger('auditable_id')->after('auditable_type');
            $table->json('old_values')->nullable()->after('auditable_id');
            $table->json('new_values')->nullable()->after('old_values');
            $table->string('ip_address', 45)->nullable()->after('new_values');
            $table->text('user_agent')->nullable()->after('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_system_logs', function (Blueprint $table) {
            $table->text('description')->nullable()->after('action_type');
            
            $table->dropColumn([
                'auditable_type',
                'auditable_id',
                'old_values',
                'new_values',
                'ip_address',
                'user_agent'
            ]);
        });
    }
};