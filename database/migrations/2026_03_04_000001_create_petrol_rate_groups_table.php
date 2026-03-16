<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_fin_petrol_rate_group', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('rate', 8, 2);
            $table->text('user_ids')->nullable()->comment('Comma-separated user IDs assigned to this rate group');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Migrate existing config data into the new table
        $existingRate = DB::table('t_fin_config')->where('config_key', 'PETROL_RATE_PER_KM')->value('config_value');
        $existingUserIds = DB::table('t_fin_config')->where('config_key', 'PETROL_AUTO_CALC_USER_IDS')->value('config_value');

        if ($existingRate) {
            DB::table('t_fin_petrol_rate_group')->insert([
                'name' => 'Default',
                'rate' => (float) $existingRate,
                'user_ids' => $existingUserIds ?: null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_fin_petrol_rate_group');
    }
};
