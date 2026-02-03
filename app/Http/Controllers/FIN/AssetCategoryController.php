<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\AssetCategoryModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssetCategoryController extends Controller
{
    /**
     * Store a new asset category
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_name' => 'required|string|max:100',
            'useful_life_years' => 'nullable|integer|min:1|max:50'
        ]);

        try {
            DB::beginTransaction();

            $categoryName = trim($request->category_name);
            
            // Generate category code from name
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $categoryName));
            $code = preg_replace('/_+/', '_', $code); // Remove multiple underscores
            $code = trim($code, '_');
            
            // Check if category already exists
            $existing = AssetCategoryModel::where('code', $code)
                ->orWhere('name', $categoryName)
                ->first();
            
            if ($existing) {
                if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => "Asset category '{$categoryName}' already exists!"
                    ], 400);
                }
                
                return redirect()->route('admin.operations')
                               ->with('error', "Asset category '{$categoryName}' already exists!");
            }
            
            // Get the highest sort order
            $maxSortOrder = AssetCategoryModel::max('sort_order') ?? 0;
            
            // Create the category
            $category = AssetCategoryModel::create([
                'code' => $code,
                'name' => $categoryName,
                'useful_life_years' => $request->useful_life_years,
                'is_active' => 1,
                'sort_order' => $maxSortOrder + 1
            ]);

            DB::commit();

            Log::info("Asset category created", [
                'category' => $categoryName,
                'code' => $code,
                'useful_life_years' => $request->useful_life_years,
                'created_by' => auth()->id()
            ]);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => "Asset category '{$categoryName}' created successfully!",
                    'category' => $category
                ]);
            }

            return redirect()->route('admin.operations')
                           ->with('success', "✓ Asset category '{$categoryName}' created successfully!");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating asset category: " . $e->getMessage());
            
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error creating asset category: ' . $e->getMessage()
                ], 500);
            }
            
            return redirect()->route('admin.operations')
                           ->with('error', 'Error creating asset category: ' . $e->getMessage());
        }
    }
}
