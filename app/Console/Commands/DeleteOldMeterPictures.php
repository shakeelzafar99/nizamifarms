<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DeleteOldMeterPictures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:delete-old-meter-pictures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete meter pictures older than 60 days from attendance records';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting deletion of old meter pictures...');

        $cutoffDate = Carbon::now()->subDays(60)->format('Y-m-d');
        
        $this->info("Cutoff date: {$cutoffDate}");

        // Get old attendance records with pictures
        $oldRecords = DB::table('t_ops_attendance')
            ->where('attendance_date', '<', $cutoffDate)
            ->where(function($q) {
                $q->whereNotNull('picture_start')
                  ->orWhereNotNull('picture_end');
            })
            ->get();

        $this->info("Found {$oldRecords->count()} records with pictures older than 60 days");

        $deletedCount = 0;
        $errorCount = 0;

        foreach ($oldRecords as $record) {
            // Delete picture_start
            if ($record->picture_start) {
                try {
                    if (Storage::disk('public')->exists($record->picture_start)) {
                        Storage::disk('public')->delete($record->picture_start);
                        $deletedCount++;
                        $this->line("✓ Deleted: {$record->picture_start}");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("✗ Failed to delete {$record->picture_start}: {$e->getMessage()}");
                }
            }

            // Delete picture_end
            if ($record->picture_end) {
                try {
                    if (Storage::disk('public')->exists($record->picture_end)) {
                        Storage::disk('public')->delete($record->picture_end);
                        $deletedCount++;
                        $this->line("✓ Deleted: {$record->picture_end}");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("✗ Failed to delete {$record->picture_end}: {$e->getMessage()}");
                }
            }
        }

        // Clear picture paths from database
        $clearedCount = DB::table('t_ops_attendance')
            ->where('attendance_date', '<', $cutoffDate)
            ->where(function($q) {
                $q->whereNotNull('picture_start')
                  ->orWhereNotNull('picture_end');
            })
            ->update([
                'picture_start' => null,
                'picture_end' => null,
                'updated_at' => now(),
            ]);

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("✓ Deleted {$deletedCount} meter pictures");
        $this->info("✓ Cleared {$clearedCount} database records");
        if ($errorCount > 0) {
            $this->warn("⚠ {$errorCount} errors occurred");
        }
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return 0;
    }
}

