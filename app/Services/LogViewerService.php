<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;

class LogViewerService
{
    protected $logPath;
    protected $logFile;

    public function __construct()
    {
        $this->logPath = storage_path('logs');
        $this->logFile = $this->logPath . '/laravel.log';
    }

    /**
     * Get error logs with filtering and pagination
     */
    public function getErrorLogs($filters = [])
    {
        if (!File::exists($this->logFile)) {
            return [
                'logs' => [],
                'total' => 0,
                'summary' => $this->getEmptySummary()
            ];
        }

        $logs = $this->parseLogFile();
        $filteredLogs = $this->applyFilters($logs, $filters);
        $summary = $this->generateSummary($filteredLogs);

        // Sort by date descending (newest first)
        $sortedLogs = $filteredLogs->sortByDesc('timestamp');

        // Apply pagination
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 50;
        $offset = ($page - 1) * $perPage;
        
        $paginatedLogs = $sortedLogs->slice($offset, $perPage)->values();

        return [
            'logs' => $paginatedLogs->toArray(),
            'total' => $filteredLogs->count(),
            'summary' => $summary,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($filteredLogs->count() / $perPage)
        ];
    }

    /**
     * Parse the Laravel log file
     */
    protected function parseLogFile()
    {
        $content = File::get($this->logFile);
        $lines = explode("\n", $content);
        
        $logs = collect();
        $currentLog = null;
        
        foreach ($lines as $line) {
            // Check if line starts with timestamp pattern [YYYY-MM-DD HH:MM:SS]
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+)/', $line, $matches)) {
                // Save previous log if exists
                if ($currentLog) {
                    $logs->push($currentLog);
                }
                
                // Start new log entry
                $currentLog = [
                    'timestamp' => Carbon::parse($matches[1]),
                    'date' => Carbon::parse($matches[1])->format('Y-m-d'),
                    'time' => Carbon::parse($matches[1])->format('H:i:s'),
                    'environment' => $matches[2],
                    'level' => strtoupper($matches[3]),
                    'message' => $matches[4],
                    'full_message' => $line,
                    'stacktrace' => [],
                    'category' => $this->categorizeError($matches[4]),
                    'is_api_related' => $this->isApiRelated($matches[4])
                ];
            } elseif ($currentLog && !empty(trim($line))) {
                // Add to stacktrace or extend message
                if (strpos($line, '#') === 0 || strpos($line, '[stacktrace]') === 0) {
                    $currentLog['stacktrace'][] = $line;
                } else {
                    $currentLog['full_message'] .= "\n" . $line;
                }
            }
        }
        
        // Add the last log
        if ($currentLog) {
            $logs->push($currentLog);
        }
        
        // Filter only error levels
        return $logs->filter(function ($log) {
            return in_array($log['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);
        });
    }

    /**
     * Apply filters to logs
     */
    protected function applyFilters(Collection $logs, array $filters)
    {
        if (!empty($filters['date_from'])) {
            $logs = $logs->filter(function ($log) use ($filters) {
                return $log['timestamp']->gte(Carbon::parse($filters['date_from'])->startOfDay());
            });
        }

        if (!empty($filters['date_to'])) {
            $logs = $logs->filter(function ($log) use ($filters) {
                return $log['timestamp']->lte(Carbon::parse($filters['date_to'])->endOfDay());
            });
        }

        if (!empty($filters['level'])) {
            $logs = $logs->filter(function ($log) use ($filters) {
                return $log['level'] === strtoupper($filters['level']);
            });
        }

        if (!empty($filters['category'])) {
            $logs = $logs->filter(function ($log) use ($filters) {
                return $log['category'] === $filters['category'];
            });
        }

        if (!empty($filters['api_only']) && $filters['api_only'] === 'true') {
            $logs = $logs->filter(function ($log) {
                return $log['is_api_related'];
            });
        }

        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $logs = $logs->filter(function ($log) use ($search) {
                return str_contains(strtolower($log['message']), $search) ||
                       str_contains(strtolower($log['full_message']), $search);
            });
        }

        return $logs;
    }

    /**
     * Categorize error based on message content
     */
    protected function categorizeError($message)
    {
        $message = strtolower($message);

        if (str_contains($message, 'shopify') || str_contains($message, 'shop.myshopify.com')) {
            return 'Shopify API';
        }

        if (str_contains($message, 'woocommerce') || str_contains($message, 'woo') || 
            str_contains($message, 'wp-json') || str_contains($message, 'wordpress')) {
            return 'WooCommerce API';
        }

        if (str_contains($message, 'curl') || str_contains($message, 'http') || 
            str_contains($message, 'api') || str_contains($message, 'request') ||
            str_contains($message, 'connection') || str_contains($message, 'timeout')) {
            return 'API/HTTP';
        }

        if (str_contains($message, 'sqlstate') || str_contains($message, 'database') || 
            str_contains($message, 'mysql') || str_contains($message, 'connection')) {
            return 'Database';
        }

        if (str_contains($message, 'route') || str_contains($message, 'not defined') || 
            str_contains($message, 'method not allowed')) {
            return 'Routing';
        }

        if (str_contains($message, 'vite') || str_contains($message, 'manifest') || 
            str_contains($message, 'asset')) {
            return 'Assets';
        }

        if (str_contains($message, 'view') || str_contains($message, 'blade') || 
            str_contains($message, 'template')) {
            return 'View/Template';
        }

        if (str_contains($message, 'auth') || str_contains($message, 'permission') || 
            str_contains($message, 'unauthorized')) {
            return 'Authentication';
        }

        return 'General';
    }

    /**
     * Check if error is API related
     */
    protected function isApiRelated($message)
    {
        $message = strtolower($message);
        
        $apiKeywords = [
            'shopify', 'shop.myshopify.com', 'woocommerce', 'woo', 'wp-json', 'wordpress',
            'curl', 'http', 'api', 'request', 'response', 'endpoint', 'webhook',
            'connection', 'timeout', 'ssl', 'certificate', 'oauth', 'token'
        ];

        foreach ($apiKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate summary statistics
     */
    protected function generateSummary(Collection $logs)
    {
        $summary = [
            'total_errors' => $logs->count(),
            'api_errors' => $logs->where('is_api_related', true)->count(),
            'by_level' => [],
            'by_category' => [],
            'by_date' => [],
            'recent_errors' => 0
        ];

        // Group by level
        $summary['by_level'] = $logs->groupBy('level')
            ->map->count()
            ->toArray();

        // Group by category
        $summary['by_category'] = $logs->groupBy('category')
            ->map->count()
            ->sortByDesc(function ($count) {
                return $count;
            })
            ->toArray();

        // Group by date (last 7 days)
        $last7Days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $count = $logs->where('date', $date)->count();
            $last7Days->push([
                'date' => $date,
                'count' => $count,
                'formatted_date' => Carbon::parse($date)->format('M j')
            ]);
        }
        $summary['by_date'] = $last7Days->toArray();

        // Recent errors (last 24 hours)
        $summary['recent_errors'] = $logs->filter(function ($log) {
            return $log['timestamp']->gte(Carbon::now()->subDay());
        })->count();

        return $summary;
    }

    /**
     * Get empty summary for when no logs exist
     */
    protected function getEmptySummary()
    {
        return [
            'total_errors' => 0,
            'api_errors' => 0,
            'by_level' => [],
            'by_category' => [],
            'by_date' => [],
            'recent_errors' => 0
        ];
    }

    /**
     * Get available log dates
     */
    public function getAvailableDates()
    {
        if (!File::exists($this->logFile)) {
            return [];
        }

        $logs = $this->parseLogFile();
        
        return $logs->pluck('date')
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Clear old log entries (keep last N days)
     */
    public function clearOldLogs($keepDays = 30)
    {
        if (!File::exists($this->logFile)) {
            return false;
        }

        $cutoffDate = Carbon::now()->subDays($keepDays);
        $logs = $this->parseLogFile();
        
        $filteredLogs = $logs->filter(function ($log) use ($cutoffDate) {
            return $log['timestamp']->gte($cutoffDate);
        });

        // Rebuild log file content
        $content = '';
        foreach ($filteredLogs as $log) {
            $content .= $log['full_message'] . "\n";
            foreach ($log['stacktrace'] as $stackLine) {
                $content .= $stackLine . "\n";
            }
        }

        return File::put($this->logFile, $content);
    }

    /**
     * Get log file size and info
     */
    public function getLogInfo()
    {
        if (!File::exists($this->logFile)) {
            return [
                'exists' => false,
                'size' => 0,
                'size_formatted' => '0 B',
                'last_modified' => null
            ];
        }

        $size = File::size($this->logFile);
        $lastModified = Carbon::createFromTimestamp(File::lastModified($this->logFile));

        return [
            'exists' => true,
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'last_modified' => $lastModified,
            'last_modified_formatted' => $lastModified->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes($size, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }
}
