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
                'code' => 840,                  // Current versionCode (increment this when releasing new version)
                'name' => '8.4.0',              // Current versionName (user-facing version)
                'download_url' => url('/apk/latest'),  // Serves APK directly with proper headers
                'release_notes' => 'Bug fixes and improvements.',
                'force_update' => true,        // Set to true to force users to update
                'min_supported_version' => 800  // Minimum versionCode that can still use the app
            ]
        ]);
    }
}

