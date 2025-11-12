<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('t_fin_ledger', function (Blueprint $table) {
            // Add posted_date column after transaction_date
            // This is the date when the transaction is entered into the system
            // Different from transaction_date which is when the transaction actually occurred
            // Defaults to transaction_date for backward compatibility
            $table->date('posted_date')->nullable()->after('transaction_date');
            
            // Add index for better query performance on posted_date
            $table->index('posted_date');
        });
        
        // Backfill existing records: set posted_date = transaction_date
        DB::statement('UPDATE t_fin_ledger SET posted_date = transaction_date WHERE posted_date IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('t_fin_ledger', function (Blueprint $table) {
            $table->dropIndex(['posted_date']);
            $table->dropColumn('posted_date');
        });
    }
};
