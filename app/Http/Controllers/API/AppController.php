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
                'code' => 113,                  // Current versionCode (increment this when releasing new version)
                'name' => '1.1.3',              // Current versionName (user-facing version)
                'download_url' => url('/downloads/NizamiFarms-Rider.apk'),
                'release_notes' => 'Fixed tab bar visibility on Samsung S25 and modern Android devices with gesture navigation.',
                'force_update' => false,        // Set to true to force users to update
                'min_supported_version' => 110  // Minimum versionCode that can still use the app
            ]
        ]);
    }
}

