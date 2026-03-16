<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_req_master', function (Blueprint $table) {
            $table->decimal('meter_distance', 10, 2)->nullable()->after('expense_date');
            $table->decimal('petrol_rate', 8, 2)->nullable()->after('meter_distance');
            $table->unsignedBigInteger('attendance_id')->nullable()->after('petrol_rate');
        });

        // Seed default petrol rate config
        DB::table('t_fin_config')->insertOrIgnore([
            'config_key' => 'PETROL_RATE_PER_KM',
            'config_value' => '10',
            'description' => 'Petrol reimbursement rate per kilometer (Rs/km)',
        ]);
    }

    public function down(): void
    {
        Schema::table('t_req_master', function (Blueprint $table) {
            $table->dropColumn(['meter_distance', 'petrol_rate', 'attendance_id']);
        });

        DB::table('t_fin_config')->where('config_key', 'PETROL_RATE_PER_KM')->delete();
    }
};
