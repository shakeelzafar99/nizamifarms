<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /**
     * Serve files stored on the 'public' disk without requiring a symlink.
     * Example URL: /public-storage/attendance/meters/2025/10/file.jpg
     */
    public function publicStorage(Request $request, string $path)
    {
        // Normalize path to prevent directory traversal
        $normalizedPath = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');

        // Try via storage disk first
        $disk = Storage::disk('public');
        if ($disk->exists($normalizedPath)) {
            return $disk->response($normalizedPath);
        }

        // Fallback: absolute path check (some envs don't resolve disk paths correctly)
        $absolutePath = storage_path('app/public/' . $normalizedPath);
        if (file_exists($absolutePath)) {
            return response()->file($absolutePath);
        }

        \Log::warning('publicStorage file not found', [
            'requested' => $path,
            'normalized' => $normalizedPath,
            'absolute_checked' => $absolutePath,
        ]);
        abort(404);
    }
}


