<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Get latest app version information
     * Used by mobile app to check for updates
     */
    public function getLatestVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 2,                    // Current versionCode (increment this when releasing new version)
                'name' => '1.1.0',              // Current versionName (user-facing version)
                'download_url' => url('/downloads/NizamiFarms-Rider.apk'),
                'release_notes' => 'Initial production release with GPS tracking, attendance, and settlements.',
                'force_update' => false,        // Set to true to force users to update
                'min_supported_version' => 1    // Minimum versionCode that can still use the app
            ]
        ]);
    }
}

