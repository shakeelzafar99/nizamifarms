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
                'code' => 182,                  // Current versionCode (increment this when releasing new version)
                'name' => '1.8.2',              // Current versionName (user-facing version)
                'download_url' => url('/downloads/NizamiFarms-Rider.apk'),
                'release_notes' => 'Bug fixes and improvements.',
                'force_update' => true,        // Set to true to force users to update
                'min_supported_version' => 150  // Minimum versionCode that can still use the app
            ]
        ]);
    }
}

