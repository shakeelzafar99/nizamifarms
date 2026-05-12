<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Latest PRIMARY app version.
     *
     * Served at GET /api/app/version and consumed by the main
     * NF mobile APK (applicationId: com.nizamifarmsmobile). The APK that the
     * download_url points at MUST have this same applicationId, otherwise
     * Android will install it as a new app instead of upgrading in place.
     */
    public function getLatestVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 970,                  // Current versionCode (increment this when releasing new version)
                'name' => '9.7.0',              // Current versionName (user-facing version)
                'download_url' => url('/apk/latest'),  // Serves APK directly with proper headers
                'release_notes' => 'Bug fixes and improvements.',
                'force_update' => true,        // Set to true to force users to update
                'min_supported_version' => 800  // Minimum versionCode that can still use the app
            ]
        ]);
    }

    /**
     * Latest QURBANI companion app version.
     *
     * Served at GET /api/app/version/qurbani and consumed ONLY by the
     * com.nizamifarmsmobile.qurbani APK. Its download_url points at
     * /apk/qurbani-latest which serves the .qurbani APK. Kept fully separate
     * from the primary tracker above so shipping one app never forces the
     * other to update (which is important since the Qurbani build is a
     * temporary event companion and rarely changes).
     *
     * The values here are maintained by build-production-apk-auto.bat when
     * the operator picks "Qurbani only" or "Both".
     */
    public function getLatestQurbaniVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 951,                                    // Qurbani versionCode - bumped by build script
                'name' => '9.5.1-qurbani',                        // Qurbani versionName (suffixed by gradle)
                'download_url' => url('/apk/qurbani-latest'),     // Serves com.nizamifarmsmobile.qurbani APK
                'release_notes' => 'Qurbani companion app updates.',
                'force_update' => false,                          // Soft prompt - user can postpone
                'min_supported_version' => 800
            ]
        ]);
    }
}
