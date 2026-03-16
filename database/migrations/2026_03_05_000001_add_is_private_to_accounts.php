<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_fin_accounts', function (Blueprint $table) {
            $table->tinyInteger('is_private')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('t_fin_accounts', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
