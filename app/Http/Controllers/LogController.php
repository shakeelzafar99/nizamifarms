<?php

namespace App\Http\Controllers;

use App\Services\LogViewerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogController extends Controller
{
    protected $logService;

    public function __construct(LogViewerService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Display the logs viewer page
     */
    public function index()
    {
        $user = Auth::user();
        $logInfo = $this->logService->getLogInfo();
        $availableDates = $this->logService->getAvailableDates();
        
        return view('logs.index', compact('user', 'logInfo', 'availableDates'));
    }

    /**
     * Get logs data via AJAX
     */
    public function getLogs(Request $request)
    {
        $filters = $request->only([
            'date_from', 'date_to', 'level', 'category', 
            'api_only', 'search', 'page', 'per_page'
        ]);

        $data = $this->logService->getErrorLogs($filters);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get log summary statistics
     */
    public function getSummary(Request $request)
    {
        $filters = $request->only([
            'date_from', 'date_to', 'level', 'category', 'api_only'
        ]);

        $data = $this->logService->getErrorLogs($filters);

        return response()->json([
            'success' => true,
            'summary' => $data['summary']
        ]);
    }

    /**
     * Get available dates for filtering
     */
    public function getAvailableDates()
    {
        $dates = $this->logService->getAvailableDates();

        return response()->json([
            'success' => true,
            'dates' => $dates
        ]);
    }

    /**
     * Get log file information
     */
    public function getLogInfo()
    {
        $info = $this->logService->getLogInfo();

        return response()->json([
            'success' => true,
            'info' => $info
        ]);
    }

    /**
     * Clear old logs (admin only)
     */
    public function clearOldLogs(Request $request)
    {
        // Check if user has admin privileges (you can customize this check)
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $keepDays = $request->get('keep_days', 30);
        
        if ($keepDays < 1 || $keepDays > 365) {
            return response()->json([
                'success' => false,
                'message' => 'Keep days must be between 1 and 365.'
            ], 400);
        }

        $result = $this->logService->clearOldLogs($keepDays);

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => "Successfully cleared logs older than {$keepDays} days."
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear old logs.'
            ], 500);
        }
    }

    /**
     * Export logs as JSON (for backup/analysis)
     */
    public function exportLogs(Request $request)
    {
        $filters = $request->only([
            'date_from', 'date_to', 'level', 'category', 'api_only'
        ]);

        // Remove pagination for export
        unset($filters['page'], $filters['per_page']);
        
        $data = $this->logService->getErrorLogs($filters);

        $filename = 'error_logs_' . date('Y-m-d_H-i-s') . '.json';

        return response()->json($data['logs'])
            ->header('Content-Disposition', "attachment; filename={$filename}")
            ->header('Content-Type', 'application/json');
    }

    /**
     * Test logging functionality to verify daily logs are working
     */
    public function testLogging()
    {
        $user = Auth::user();
        $timestamp = now()->format('Y-m-d H:i:s');
        
        // Get current log configuration
        $logChannel = config('logging.default');
        $stackChannels = config('logging.channels.stack.channels', []);
        $logPath = storage_path('logs');
        
        // Write test logs at different levels
        \Log::info('TEST LOG: Daily logging test - INFO level', [
            'user_id' => $user->id ?? 'guest',
            'timestamp' => $timestamp,
            'log_channel' => $logChannel,
            'stack_channels' => $stackChannels,
        ]);
        
        \Log::warning('TEST LOG: Daily logging test - WARNING level', [
            'user_id' => $user->id ?? 'guest',
            'timestamp' => $timestamp,
        ]);
        
        \Log::error('TEST LOG: Daily logging test - ERROR level', [
            'user_id' => $user->id ?? 'guest',
            'timestamp' => $timestamp,
        ]);
        
        // Check for daily log files
        $todayDate = now()->format('Y-m-d');
        $dailyLogFile = $logPath . '/laravel-' . $todayDate . '.log';
        $singleLogFile = $logPath . '/laravel.log';
        
        $dailyLogExists = file_exists($dailyLogFile);
        $singleLogExists = file_exists($singleLogFile);
        
        // Get all log files
        $allLogFiles = glob($logPath . '/laravel*.log');
        $logFilesList = array_map(function($file) use ($logPath) {
            $filename = basename($file);
            $size = filesize($file);
            $modified = date('Y-m-d H:i:s', filemtime($file));
            return [
                'name' => $filename,
                'size' => round($size / 1024, 2) . ' KB',
                'modified' => $modified
            ];
        }, $allLogFiles);
        
        return response()->json([
            'success' => true,
            'message' => 'Test logs written successfully',
            'configuration' => [
                'log_channel' => $logChannel,
                'stack_channels' => $stackChannels,
                'log_path' => $logPath,
            ],
            'log_files' => [
                'daily_log_file' => $dailyLogFile,
                'daily_log_exists' => $dailyLogExists,
                'single_log_file' => $singleLogFile,
                'single_log_exists' => $singleLogExists,
            ],
            'all_log_files' => $logFilesList,
            'test_info' => [
                'timestamp' => $timestamp,
                'user_id' => $user->id ?? 'guest',
                'logs_written' => 3,
                'levels' => ['INFO', 'WARNING', 'ERROR']
            ]
        ]);
    }
}
