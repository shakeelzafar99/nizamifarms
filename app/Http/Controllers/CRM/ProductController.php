<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CRM\ProductModel;
use App\Services\ShopifyService;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected $shopify;
    protected $wooCommerce;

    public function __construct(ShopifyService $shopify, WooCommerceService $wooCommerce)
    {
        $this->shopify = $shopify;
        $this->wooCommerce = $wooCommerce;
    }

    /**
     * Attribute settings UI (labels from JSON; groups are transient via request form only)
     */
    public function attributes()
    {
        $labels = $this->readAttributeLabels();
        $activeKey = (int) request()->get('level', 1);
        $auto = $this->readAttributeAutoRules();
        $activeRules = $auto[(string) $activeKey] ?? [];
        // Sort by priority desc for display
        usort($activeRules, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
        // Current DB assignments summary for the selected level (for info only)
        $column = 'attribute_' . $activeKey;
        $assignStats = \DB::table('t_crm_prod_product')
            ->select($column . ' as value', \DB::raw('COUNT(*) as cnt'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('cnt')
            ->limit(20)
            ->get();
        
        // Get existing categories for the dropdown (for all levels)
        $existingCategories = [
            '1' => \DB::table('t_crm_prod_product')
                ->select('attribute_1 as value')
                ->whereNotNull('attribute_1')
                ->where('attribute_1', '!=', '')
                ->groupBy('attribute_1')
                ->orderBy('attribute_1')
                ->pluck('value')
                ->toArray(),
            '2' => \DB::table('t_crm_prod_product')
                ->select('attribute_2 as value')
                ->whereNotNull('attribute_2')
                ->where('attribute_2', '!=', '')
                ->groupBy('attribute_2')
                ->orderBy('attribute_2')
                ->pluck('value')
                ->toArray(),
            '3' => \DB::table('t_crm_prod_product')
                ->select('attribute_3 as value')
                ->whereNotNull('attribute_3')
                ->where('attribute_3', '!=', '')
                ->groupBy('attribute_3')
                ->orderBy('attribute_3')
                ->pluck('value')
                ->toArray(),
        ];
        
        // Check if user has permission to edit priorities (Taimur role only)
        $user = auth()->user();
        $canEditPriorities = false;
        if ($user) {
            // Check if user has Taimur role (by role name, not ID)
            $canEditPriorities = $user->roles()
                ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                ->exists();
        }
        
        return view('pages.products.attributes', compact('labels', 'activeKey', 'activeRules', 'assignStats', 'existingCategories', 'canEditPriorities'));
    }

    public function saveAttributeLabels(Request $request)
    {
        $labels = $this->readAttributeLabels();
        foreach ([1,2,3] as $key) {
            $label = trim((string) $request->input('label_'.$key));
            if ($label !== '') {
                $labels[(string)$key] = $label;
            }
        }
        // Optional auto-rules payload (when sent from UI). Persist to JSON file.
        $autoRules = $request->input('auto_rules');
        if (is_array($autoRules)) {
            $this->writeAttributeAutoRules($autoRules);
        }
        $this->writeAttributeLabels($labels);
        return back()->with('success', 'Attribute labels saved.');
    }

    // Removed persistent group creation; groups become transient via UI for one-time apply

    // Removed explicit group persistence paths

    public function applyAttributeRules(Request $request)
    {
        $validated = $request->validate([
            'attribute_key' => 'required|in:1,2,3',
            'group_name' => 'required|string|max:150',
            'match_string' => 'nullable|string|max:255',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
        ]);

        $attributeKey = (int) $validated['attribute_key'];
        $column = 'attribute_' . $attributeKey;

        $query = ProductModel::query();
        if (!empty($validated['product_type'])) $query->where('product_type', $validated['product_type']);
        if (!empty($validated['vendor'])) $query->where('vendor', $validated['vendor']);

        $products = $query->get();
        $updated = 0;

        foreach ($products as $product) {
            $assign = false;
            if (!empty($validated['match_string'])) {
                if (stripos($product->title ?? '', $validated['match_string']) !== false) {
                    $assign = true;
                }
            }
            if (!$assign && !empty($validated['product_ids']) && in_array($product->id, $validated['product_ids'])) {
                $assign = true;
            }
            if ($assign) {
                $product->{$column} = $validated['group_name'];
                $product->save();
                $updated++;
            }
        }

        return back()->with('success', "Applied to {$updated} products.");
    }

    public function previewAttributeRules(Request $request)
    {
        $validated = $request->validate([
            'attribute_key' => 'required|in:1,2,3',
            'group_name' => 'required|string|max:150',
            'match_string' => 'nullable|string|max:255',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
        ]);

        $query = ProductModel::query();
        if (!empty($validated['product_type'])) $query->where('product_type', $validated['product_type']);
        if (!empty($validated['vendor'])) $query->where('vendor', $validated['vendor']);

        $products = $query->select('id','title')->get();
        $count = 0;
        $sample = [];
        foreach ($products as $product) {
            $assign = false;
            if (!empty($validated['match_string'])) {
                if (stripos($product->title ?? '', $validated['match_string']) !== false) {
                    $assign = true;
                }
            }
            if (!$assign && !empty($validated['product_ids']) && in_array($product->id, $validated['product_ids'])) {
                $assign = true;
            }
            if ($assign) {
                $count++;
                if (count($sample) < 10) $sample[] = ['id' => $product->id, 'title' => $product->title];
            }
        }

        return response()->json(['success' => true, 'count' => $count, 'sample' => $sample]);
    }

    private function labelsPath(): string
    {
        return storage_path('app/private/attribute_labels.json');
    }

    private function readAttributeLabels(): array
    {
        $path = $this->labelsPath();
        $defaults = ['1' => 'Category Level 1', '2' => 'Category Level 2', '3' => 'Category Level 3'];
        if (!is_file($path)) { return $defaults; }
        $json = file_get_contents($path);
        $data = json_decode($json, true) ?: [];
        
        // Handle both numeric array format and string key format
        $normalized = $defaults;
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            if (isset($data[$stringKey])) {
                $normalized[$stringKey] = $data[$stringKey];
            } elseif (isset($data[$key])) {
                $normalized[$stringKey] = $data[$key];
            }
            
            // Normalize any legacy 'Attribute X' labels to Category Level X
            $v = (string)($normalized[$stringKey] ?? '');
            if (preg_match('/^\s*Attribute\s*' . $key . '\s*$/i', $v)) {
                $normalized[$stringKey] = $defaults[$stringKey];
            }
        }
        return $normalized;
    }

    private function writeAttributeLabels(array $labels): void
    {
        $path = $this->labelsPath();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        
        // Ensure we save with string keys for consistency
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $labels[$stringKey] ?? ('Category Level ' . $key);
        }
        
        file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function autoRulesPath(): string
    {
        return storage_path('app/private/attribute_auto_rules.json');
    }

    private function readAttributeAutoRules(): array
    {
        $path = $this->autoRulesPath();
        if (!is_file($path)) return ['1'=>[], '2'=>[], '3'=>[]];
        $data = json_decode(file_get_contents($path), true) ?: [];
        
        // Handle both numeric array format and string key format
        $normalized = ['1'=>[], '2'=>[], '3'=>[]];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            if (isset($data[$stringKey])) {
                $normalized[$stringKey] = $data[$stringKey];
            } elseif (isset($data[$key])) {
                $normalized[$stringKey] = $data[$key];
            }
        }
        return $normalized;
    }

    private function writeAttributeAutoRules(array $rules): void
    {
        $path = $this->autoRulesPath();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        
        // Ensure we save with string keys for consistency
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $rules[$stringKey] ?? [];
        }
        
        file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // Lightweight product lookup for selectors
    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $limit = (int) ($request->get('limit', 15));
        $limit = max(1, min(50, $limit));

        $query = ProductModel::select('id', 'title')
            ->when($q !== '', function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%");
            })
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'products' => $query]);
    }

    public function saveAutoRules(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3',
                'rules' => 'nullable|array', // Changed from 'required' to 'nullable' to allow empty array
                'rules.*.match' => 'required|string',
                'rules.*.group' => 'required|string',
                'rules.*.priority' => 'nullable|integer',
            ]);
            
            // Ensure rules is an array even if null
            $validated['rules'] = $validated['rules'] ?? [];
        $all = $this->readAttributeAutoRules();
        $all[(string)$validated['attribute_key']] = array_values($validated['rules']);
        $this->writeAttributeAutoRules($all);
        
        // Generate summary of rule matches
        $attributeKey = $validated['attribute_key'];
        $column = 'attribute_' . $attributeKey;
        $rules = $validated['rules'];
        $summary = [];
        
        foreach ($rules as $rule) {
            $needle = trim((string)($rule['match'] ?? ''));
            $group = trim((string)($rule['group'] ?? ''));
            if ($needle === '' || $group === '') continue;
            
            // Count products that are ACTUALLY assigned to this category
            // (not just products that match the search word)
            $count = \DB::table('t_crm_prod_product')
                ->where($column, '=', $group)
                ->count();
            
            $summary[] = [
                'match' => $needle,
                'group' => $group,
                'priority' => $rule['priority'] ?? 0,
                'matching_products' => $count
            ];
        }
        
        // Count uncategorized products for this level
        $totalProducts = \DB::table('t_crm_prod_product')->count();
        $categorizedProducts = \DB::table('t_crm_prod_product')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->count();
        $uncategorizedProducts = $totalProducts - $categorizedProducts;
        
        // Get sample of uncategorized products (top 20)
        $uncategorizedSample = \DB::table('t_crm_prod_product')
            ->select('id', 'title', 'product_type', 'vendor')
            ->where(function($q) use ($column) {
                $q->whereNull($column)
                  ->orWhere($column, '=', '');
            })
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();
        
        return response()->json([
            'success' => true,
            'summary' => $summary,
            'total_products' => $totalProducts,
            'categorized_products' => $categorizedProducts,
            'uncategorized_products' => $uncategorizedProducts,
            'uncategorized_sample' => $uncategorizedSample
        ]);
        
        } catch (\Exception $e) {
            \Log::error('Error in saveAutoRules: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error saving rules: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get coverage summary without saving rules
    public function getCoverageSummary(Request $request)
    {
        try {
            // Validate input
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3'
            ]);
            
            $attributeKey = (int) $validated['attribute_key'];
            
            \Log::info('Getting coverage summary', [
                'attribute_key' => $attributeKey
            ]);
            
            // Read existing saved rules from file
            $allRules = $this->readAttributeAutoRules();
            $rules = $allRules[(string)$attributeKey] ?? [];
            
            \Log::info('Found rules for level', [
                'level' => $attributeKey,
                'rule_count' => count($rules)
            ]);
            
            $column = 'attribute_' . $attributeKey;
            $summary = [];
            
            // Calculate matches for each rule
            foreach ($rules as $rule) {
                $needle = trim((string)($rule['match'] ?? ''));
                $group = trim((string)($rule['group'] ?? ''));
                if ($needle === '' || $group === '') continue;
                
                // Count products that are ACTUALLY assigned to this category
                // (not just products that match the search word)
                $count = \DB::table('t_crm_prod_product')
                    ->where($column, '=', $group)
                    ->count();
                
                $summary[] = [
                    'match' => $needle,
                    'group' => $group,
                    'priority' => $rule['priority'] ?? 0,
                    'matching_products' => $count
                ];
            }
            
            // Count categorized vs uncategorized
            $totalProducts = \DB::table('t_crm_prod_product')->count();
            $categorizedProducts = \DB::table('t_crm_prod_product')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->count();
            $uncategorizedProducts = $totalProducts - $categorizedProducts;
            
            // Get sample of uncategorized products
            $uncategorizedSample = \DB::table('t_crm_prod_product')
                ->select('id', 'title', 'product_type', 'vendor')
                ->where(function($q) use ($column) {
                    $q->whereNull($column)
                      ->orWhere($column, '=', '');
                })
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get();
            
            \Log::info('Coverage calculated', [
                'total' => $totalProducts,
                'categorized' => $categorizedProducts,
                'uncategorized' => $uncategorizedProducts
            ]);
            
            return response()->json([
                'success' => true,
                'summary' => $summary,
                'total_products' => $totalProducts,
                'categorized_products' => $categorizedProducts,
                'uncategorized_products' => $uncategorizedProducts,
                'uncategorized_sample' => $uncategorizedSample
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in getCoverageSummary', [
                'errors' => $e->errors()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid request: ' . json_encode($e->errors())
            ], 422);
            
        } catch (\Exception $e) {
            \Log::error('Error in getCoverageSummary: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading coverage: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
            ], 500);
        }
    }

    // Preview auto-rules against existing products
    public function previewAutoRules(Request $request)
    {
        $attributeKey = (int) $request->get('attribute_key', 0); // 0 means all
        $path = $this->autoRulesPath();
        if (!is_file($path)) {
            return response()->json(['success' => true, 'results' => []]);
        }
        $rules = json_decode(file_get_contents($path), true) ?: [];

        $products = ProductModel::select('id', 'title')->get();
        $results = [];

        $keys = $attributeKey && in_array($attributeKey, [1,2,3]) ? [$attributeKey] : [1,2,3];
        foreach ($keys as $key) {
            $count = 0; $sample = [];
            $rset = $rules[(string)$key] ?? [];
            // Sort by priority desc if provided
            usort($rset, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
            foreach ($products as $p) {
                $matched = false;
                foreach ($rset as $r) {
                    $needle = (string)($r['match'] ?? '');
                    $group = (string)($r['group'] ?? '');
                    // match must be whole word (use regex word boundaries) and case-insensitive
                    if ($needle !== '' && $group !== '' && preg_match('/\\b' . preg_quote($needle, '/') . '\\b/i', (string)$p->title)) {
                        $matched = true; break;
                    }
                }
                if ($matched) {
                    $count++; if (count($sample) < 10) $sample[] = ['id'=>$p->id,'title'=>$p->title];
                }
            }
            $results[$key] = ['count' => $count, 'sample' => $sample];
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    public function applySavedRules(Request $request)
    {
        $attributeKey = (int) $request->validate(['attribute_key' => 'required|in:1,2,3'])['attribute_key'];
        $rules = $this->readAttributeAutoRules();
        $rset = $rules[(string)$attributeKey] ?? [];
        usort($rset, function($a,$b){ return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0); });
        $column = 'attribute_' . $attributeKey;
        $updated = 0;
        
        // Track which product IDs have been categorized by rules (to respect priority)
        $categorizedIds = [];
        
        // Apply in priority order - highest priority first
        // Higher priority rules will take precedence by categorizing products first
        foreach ($rset as $rule) {
            $needle = trim((string)($rule['match'] ?? ''));
            $group  = trim((string)($rule['group'] ?? ''));
            if ($needle === '' || $group === '') { continue; }
            
            // Get all products that match this rule
            $matchingProducts = \DB::table('t_crm_prod_product')
                ->where('title', 'LIKE', '%'.$needle.'%')
                ->pluck('id');
            
            // Update only products that haven't been categorized by a higher priority rule
            $toUpdate = $matchingProducts->diff($categorizedIds)->toArray();
            
            if (!empty($toUpdate)) {
                $count = \DB::table('t_crm_prod_product')
                    ->whereIn('id', $toUpdate)
                    ->update([$column => $group, 'updated_at' => now()]);
                $updated += (int) $count;
                
                // Mark these products as categorized so lower priority rules don't override
                $categorizedIds = array_merge($categorizedIds, $toUpdate);
            }
        }
        
        // IMPORTANT: Clear categories from products that no longer match any rule
        // This handles the case where a rule was removed (like "Trotters" → "Paya")
        $allRuleCategories = array_unique(array_column($rset, 'group'));
        
        // Find products that have a category from the rules but don't match any current rule
        if (!empty($allRuleCategories) && !empty($categorizedIds)) {
            // Clear categories for products that were categorized but are no longer matched by any rule
            $productsToCheck = \DB::table('t_crm_prod_product')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->whereNotIn('id', $categorizedIds)
                ->get(['id', 'title', $column]);
            
            $cleared = 0;
            foreach ($productsToCheck as $product) {
                // Check if this product's current category came from a rule that no longer exists
                // We'll be conservative: only clear if the category matches a rule pattern that's been removed
                $shouldMatch = false;
                foreach ($rset as $rule) {
                    $needle = trim((string)($rule['match'] ?? ''));
                    if ($needle !== '' && stripos($product->title, $needle) !== false) {
                        $shouldMatch = true;
                        break;
                    }
                }
                
                // If product should match a rule but wasn't categorized, something's wrong
                // Or if it has a category but doesn't match any rule anymore, it might be orphaned
                // For safety, we'll just leave it alone to avoid accidental data loss
            }
        }
        
        return response()->json(['success' => true, 'updated' => $updated]);
    }

    public function index(Request $request)
    {
        // Get products with variants
        $query = ProductModel::with('variants');
        
        // Add search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            
            // Split search query into individual words for flexible matching
            $searchWords = array_filter(explode(' ', $search));
            
            $query->where(function($q) use ($search, $searchWords) {
                // If multiple words, match each word independently in title
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    // Single word search - keep original behavior
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                
                // Also search in vendor, product_type, and variants
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")
                        ->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by product category (product_type)
        if ($request->has('product_type') && $request->product_type) {
            $query->where('product_type', $request->product_type);
        }

        // Filter by attributes
        foreach (['attribute_1', 'attribute_2', 'attribute_3'] as $attr) {
            if ($request->has($attr) && $request->$attr) {
                $query->where($attr, $request->$attr);
            }
        }

        // Filter by vendor
        if ($request->has('vendor') && $request->vendor) {
            $query->where('vendor', $request->vendor);
        }

        // Filter by sync_status
        if ($request->has('sync_status') && $request->sync_status) {
            $query->where('sync_status', $request->sync_status);
        }

        $products = $query->orderBy('title')->paginate(20);

        // Get filter options based on CURRENT filters (cascading/dependent filters)
        // For each filter dropdown, we build a query that applies all OTHER active filters
        // This ensures each dropdown only shows values that actually exist for the filtered results
        
        // Base query for filter options (reusable)
        $baseFilterQuery = ProductModel::query();
        
        // Sync Status options - respect other filters
        $syncStatusQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $syncStatusQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $syncStatusQuery->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $syncStatusQuery->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $syncStatusQuery->where('vendor', $request->vendor);
        if ($request->has('attribute_1') && $request->attribute_1) $syncStatusQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $syncStatusQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $syncStatusQuery->where('attribute_3', $request->attribute_3);
        $syncStatuses = $syncStatusQuery->distinct()->pluck('sync_status')->filter()->sort();
        
        // Product Types (Category) options - respect other filters
        $productTypeQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $productTypeQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $productTypeQuery->where('status', $request->status);
        if ($request->has('vendor') && $request->vendor) $productTypeQuery->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $productTypeQuery->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $productTypeQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $productTypeQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $productTypeQuery->where('attribute_3', $request->attribute_3);
        $productTypes = $productTypeQuery->distinct()->pluck('product_type')->filter()->sort();
        
        // Vendors options - respect other filters
        $vendorQuery = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $vendorQuery->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $vendorQuery->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $vendorQuery->where('product_type', $request->product_type);
        if ($request->has('sync_status') && $request->sync_status) $vendorQuery->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $vendorQuery->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $vendorQuery->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $vendorQuery->where('attribute_3', $request->attribute_3);
        $vendors = $vendorQuery->distinct()->pluck('vendor')->filter()->sort();
        
        // Attribute 1 options - respect other filters
        $attr1Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr1Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr1Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr1Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr1Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr1Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_2') && $request->attribute_2) $attr1Query->where('attribute_2', $request->attribute_2);
        if ($request->has('attribute_3') && $request->attribute_3) $attr1Query->where('attribute_3', $request->attribute_3);
        $attribute1s = $attr1Query->distinct()->pluck('attribute_1')->filter()->sort();
        
        // Attribute 2 options - respect other filters
        $attr2Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr2Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr2Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr2Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr2Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr2Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $attr2Query->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_3') && $request->attribute_3) $attr2Query->where('attribute_3', $request->attribute_3);
        $attribute2s = $attr2Query->distinct()->pluck('attribute_2')->filter()->sort();
        
        // Attribute 3 options - respect other filters
        $attr3Query = clone $baseFilterQuery;
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $searchWords = array_filter(explode(' ', $search));
            $attr3Query->where(function($q) use ($search, $searchWords) {
                if (count($searchWords) > 1) {
                    $q->where(function($titleQuery) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $titleQuery->where('title', 'LIKE', "%{$word}%");
                        }
                    });
                } else {
                    $q->where('title', 'LIKE', "%{$search}%");
                }
                $q->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }
        if ($request->has('status') && $request->status) $attr3Query->where('status', $request->status);
        if ($request->has('product_type') && $request->product_type) $attr3Query->where('product_type', $request->product_type);
        if ($request->has('vendor') && $request->vendor) $attr3Query->where('vendor', $request->vendor);
        if ($request->has('sync_status') && $request->sync_status) $attr3Query->where('sync_status', $request->sync_status);
        if ($request->has('attribute_1') && $request->attribute_1) $attr3Query->where('attribute_1', $request->attribute_1);
        if ($request->has('attribute_2') && $request->attribute_2) $attr3Query->where('attribute_2', $request->attribute_2);
        $attribute3s = $attr3Query->distinct()->pluck('attribute_3')->filter()->sort();

        // If this is an AJAX request, return JSON
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'products' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'last_page' => $products->lastPage()
                ],
                'filter_options' => [
                    'product_types' => $productTypes->values()->toArray(),
                    'vendors' => $vendors->values()->toArray(),
                    'attribute_1s' => $attribute1s->values()->toArray(),
                    'attribute_2s' => $attribute2s->values()->toArray(),
                    'attribute_3s' => $attribute3s->values()->toArray(),
                    'sync_statuses' => $syncStatuses->values()->toArray()
                ]
            ]);
        }

        // Get attribute labels for display
        $attributeLabels = $this->readAttributeLabels();
        
        return view('pages.products.index', compact('products', 'syncStatuses', 'productTypes', 'vendors', 'attribute1s', 'attribute2s', 'attribute3s', 'attributeLabels'));
    }

    /**
     * Bulk adjust variant prices by filter and mode
     */
    /**
     * Preview bulk price adjustments without applying them
     */
    public function previewBulkAdjustPrices(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required|in:percent,fixed',
            'operation' => 'required|in:increase,decrease',
            'amount' => 'required|numeric|min:0',
            // Optional filters
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
            'attribute_1' => 'nullable|string',
            'attribute_2' => 'nullable|string',
            'attribute_3' => 'nullable|string',
        ]);

        $query = ProductModel::query();
        foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
            if ($request->$f) $query->where($f, $request->$f);
        }

        $products = $query->with('variants')->get();
        $changes = []; // Preview changes WITHOUT saving

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $old = (float) $variant->price;
                $new = $old;

                if ($validated['mode'] === 'percent') {
                    $delta = $old * ($validated['amount'] / 100);
                    $new = $validated['operation'] === 'increase' ? $old + $delta : $old - $delta;
                } else {
                    $new = $validated['operation'] === 'increase' ? $old + $validated['amount'] : $old - $validated['amount'];
                }

                // Guardrails: never below zero
                $new = max(0, round($new, 2));

                if ($new !== $old) {
                    // Just record the change, don't save
                    $changes[] = [
                        'product_title' => $product->title,
                        'variant_title' => $variant->title,
                        'sku' => $variant->sku,
                        'old_price' => $old,
                        'new_price' => $new,
                        'difference' => $new - $old,
                        'difference_percent' => $old > 0 ? round((($new - $old) / $old) * 100, 2) : 0
                    ];
                }
            }
        }

        $affectedVariants = count($changes);
        $affectedProducts = $products->filter(function($product) use ($changes) {
            return collect($changes)->contains(function($change) use ($product) {
                return $change['product_title'] === $product->title;
            });
        })->count();

        $message = "Will update {$affectedProducts} products ({$affectedVariants} variants)";

        return response()->json([
            'success' => true,
            'preview' => true,
            'affected_variants' => $affectedVariants,
            'affected_products' => $affectedProducts,
            'message' => $message,
            'changes' => $changes
        ]);
    }

    public function bulkAdjustPrices(Request $request)
    {
        $validated = $request->validate([
            'mode' => 'required|in:percent,fixed',
            'operation' => 'required|in:increase,decrease',
            'amount' => 'required|numeric|min:0',
            // Optional filters
            'product_type' => 'nullable|string',
            'vendor' => 'nullable|string',
            'attribute_1' => 'nullable|string',
            'attribute_2' => 'nullable|string',
            'attribute_3' => 'nullable|string',
        ]);

        $query = ProductModel::query();
        foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
            if ($request->$f) $query->where($f, $request->$f);
        }

        $products = $query->with('variants')->get();
        $affectedVariants = 0;
        $affectedProducts = 0;
        $changes = []; // Track all price changes for detailed summary

        foreach ($products as $product) {
            $productUpdated = false;
            foreach ($product->variants as $variant) {
                $old = (float) $variant->price;
                $new = $old;

                if ($validated['mode'] === 'percent') {
                    $delta = $old * ($validated['amount'] / 100);
                    $new = $validated['operation'] === 'increase' ? $old + $delta : $old - $delta;
                } else {
                    $new = $validated['operation'] === 'increase' ? $old + $validated['amount'] : $old - $validated['amount'];
                }

                // Guardrails: never below zero
                $new = max(0, round($new, 2));

                if ($new !== $old) {
                    $variant->price = $new;
                    $variant->save();
                    $affectedVariants++;
                    $productUpdated = true;
                    
                    // Record this change for the summary
                    $changes[] = [
                        'product_title' => $product->title,
                        'variant_title' => $variant->title,
                        'sku' => $variant->sku,
                        'old_price' => $old,
                        'new_price' => $new,
                        'difference' => $new - $old,
                        'difference_percent' => $old > 0 ? round((($new - $old) / $old) * 100, 2) : 0
                    ];
                }
            }

            if ($productUpdated) {
                $affectedProducts++;
                // Update cached price range on product
                $prices = $product->variants()->pluck('price')->toArray();
                if (!empty($prices)) {
                    $product->price_min = min($prices);
                    $product->price_max = max($prices);
                    $product->save();
                }
            }
        }

        $message = "Updated {$affectedProducts} products ({$affectedVariants} variants)";
        if ($affectedProducts !== $affectedVariants) {
            $message .= " - Some products have multiple variants";
        }

        return response()->json([
            'success' => true,
            'affected_variants' => $affectedVariants,
            'affected_products' => $affectedProducts,
            'message' => $message,
            'changes' => $changes // Return detailed changes for frontend display
        ]);
    }

    public function show($id)
    {
        try {
            $product = ProductModel::with('variants')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'product' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            // Split search query into individual words for flexible matching
            $searchWords = array_filter(explode(' ', $query));
            
            $products = \App\Models\CRM\ProductModel::with('variants')
                ->where('is_active', true)
                ->where(function($q) use ($query, $searchWords) {
                    // Create hyphen-less version for flexible SKU search
                    $queryNoHyphens = str_replace(['-', ' '], '', $query);
                    
                    // If multiple words, match each word independently in title (in any order)
                    if (count($searchWords) > 1) {
                        $q->where(function($titleQuery) use ($searchWords) {
                            foreach ($searchWords as $word) {
                                $titleQuery->where('title', 'LIKE', "%{$word}%");
                            }
                        });
                    } else {
                        // Single word search - keep original behavior
                        $q->where('title', 'LIKE', "%{$query}%");
                    }
                    
                    // Also search in variants
                    $q->orWhereHas('variants', function($vq) use ($query, $queryNoHyphens, $searchWords) {
                        if (count($searchWords) > 1) {
                            // Multi-word search in variant title
                            $vq->where(function($titleQuery) use ($searchWords) {
                                foreach ($searchWords as $word) {
                                    $titleQuery->where('title', 'LIKE', "%{$word}%");
                                }
                            });
                        } else {
                            $vq->where('title', 'LIKE', "%{$query}%");
                        }
                        
                        // SKU search (always use full query)
                        $vq->orWhere('sku', 'LIKE', "%{$query}%")
                            ->orWhereRaw('REPLACE(REPLACE(sku, "-", ""), " ", "") LIKE ?', ["%{$queryNoHyphens}%"]);
                      });
                })
                ->limit($limit)
                ->get();
            
            $results = [];
            foreach ($products as $product) {
                // Only add variants, not the parent product (to avoid duplicates)
                foreach ($product->variants as $variant) {
                    if ($variant->available) {
                        // For single variants, use just the product title
                        // For multiple variants, append variant title if it's different
                        $displayName = $product->title;
                        if (count($product->variants) > 1 && $variant->title && $variant->title !== $product->title) {
                            $displayName .= ' - ' . $variant->title;
                        }
                        
                        $results[] = [
                            'id' => 'variant_' . $variant->id,
                            'type' => 'variant',
                            'name' => $displayName,
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'inventory' => $variant->inventory_quantity,
                            'vendor' => $product->vendor
                        ];
                    }
                }
                
                // If no available variants, still show the product (but mark as unavailable)
                if ($product->variants->where('available', true)->count() === 0 && $product->variants->count() > 0) {
                    $firstVariant = $product->variants->first();
                    $results[] = [
                        'id' => 'variant_' . $firstVariant->id,
                        'type' => 'variant',
                        'name' => $product->title . ' (Out of Stock)',
                        'sku' => $firstVariant->sku,
                        'price' => $firstVariant->price,
                        'inventory' => 0,
                        'vendor' => $product->vendor
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'products' => $results,
                'debug' => [
                    'query' => $query,
                    'products_found' => $products->count(),
                    'results_count' => count($results)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import products from Shopify (limited)
     */
    public function importProducts(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:250'
            ]);

            $limit = $validated['limit'] ?? 50;

            // Fetch products from Shopify
            $products = $this->shopify->fetchProducts($limit);

            if (empty($products)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No products found in Shopify store. This could mean: 1) Your store has no products, 2) API credentials are incorrect, or 3) Products are in draft status.'
                ]);
            }

            // Store products in database
            $importedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($products as $shopifyProduct) {
                try {
                    // Check if product already exists
                    $existingProduct = ProductModel::findByShopifyId($shopifyProduct['id']);
                    $isUpdate = $existingProduct !== null;
                    
                    // Create or update product
                    ProductModel::createOrUpdateFromShopify($shopifyProduct);
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Product ID {$shopifyProduct['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify product: ' . $e->getMessage(), [
                        'shopify_product_id' => $shopifyProduct['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $message = "Successfully processed " . ($importedCount + $updatedCount) . " products from Shopify.";
            if ($importedCount > 0) {
                $message .= " {$importedCount} new products imported.";
            }
            if ($updatedCount > 0) {
                $message .= " {$updatedCount} existing products updated.";
            }
            if ($errorCount > 0) {
                $message .= " {$errorCount} products failed to process.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Shopify Products Import Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while importing products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync single product from Shopify
     */
    public function syncProduct(Request $request, $id)
    {
        try {
            $product = ProductModel::findOrFail($id);

            if (!$product->shopify_product_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not linked to Shopify'
                ], 400);
            }

            // Fetch single product from Shopify
            $shopifyProduct = $this->shopify->fetchProduct($product->shopify_product_id);

            if (!$shopifyProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in Shopify'
                ], 404);
            }

            // Map and update product
            $productData = ProductModel::mapShopifyProduct($shopifyProduct);
            ProductModel::storeProductFromApi($productData);

            return response()->json([
                'success' => true,
                'message' => 'Product synced successfully',
                'product' => $product->fresh(['variants'])
            ]);

        } catch (\Exception $e) {
            Log::error('Product sync error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import ALL products from Shopify
     */
    public function importAllProducts(Request $request)
    {
        try {
            \Log::info('Starting bulk import of all products from Shopify');

            $source = $request->get('source', 'Shopify');
            $priceOnlyUpdate = $request->get('price_only_update', false);
            
            \Log::info('Import settings', [
                'source' => $source,
                'price_only_update' => $priceOnlyUpdate
            ]);
            
            if (strcasecmp($source, 'WooCommerce') === 0) {
                \Log::info('Bulk import source selected: WooCommerce');
                $wooProductsRaw = $this->wooCommerce->fetchAllProducts();
                $products = [];
                foreach ($wooProductsRaw as $wooProduct) {
                    $variations = [];
                    if (($wooProduct['type'] ?? '') === 'variable') {
                        $variations = $this->wooCommerce->fetchProductVariations((int)$wooProduct['id']);
                    }
                    $products[] = $this->wooCommerce->mapWooProduct($wooProduct, $variations);
                }
            } else {
                // Fetch ALL products from Shopify
                $products = $this->shopify->fetchAllProducts();
            }

            if (empty($products)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No products found in Shopify store. This could mean: 1) Your store has no products, 2) API credentials are incorrect, or 3) Products are in draft status.'
                ]);
            }

            \Log::info('Fetched ' . count($products) . ' products from Shopify, starting import process');

            // Store products in database
            $importedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];
            $totalProducts = count($products);

            foreach ($products as $index => $productPayload) {
                try {
                    // Add price_only_update flag to payload
                    if ($priceOnlyUpdate) {
                        $productPayload['_price_only_update'] = true;
                    }
                    
                    // Reuse store method that expects canonical payload
                    $stored = ProductModel::storeProductFromApi($productPayload);
                    $isUpdate = $stored->wasRecentlyCreated === false;
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }

                    // Log progress every 10 products
                    if (($index + 1) % 10 === 0) {
                        \Log::info("Processed " . ($index + 1) . "/{$totalProducts} products");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = $e->getMessage();
                    Log::error('Failed to import product: ' . $e->getMessage());
                }
            }

            \Log::info('Completed bulk import', [
                'total_products' => $totalProducts,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount
            ]);

            $message = "Successfully processed {$totalProducts} products from Shopify.";
            if ($importedCount > 0) {
                $message .= " {$importedCount} new products imported.";
            }
            if ($updatedCount > 0) {
                if ($priceOnlyUpdate) {
                    $message .= " {$updatedCount} existing products updated (prices only, categories/attributes preserved).";
                } else {
                    $message .= " {$updatedCount} existing products updated.";
                }
            }
            if ($errorCount > 0) {
                $message .= " {$errorCount} products failed to process.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'total_products' => $totalProducts,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk product import error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while importing all products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form for creating a new product
     */
    public function create()
    {
        // Get distinct values for dropdowns (same as index page filters)
        $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort()->values();
        $vendors = ProductModel::distinct()->pluck('vendor')->filter()->sort()->values();
        $attribute1s = ProductModel::distinct()->pluck('attribute_1')->filter()->sort()->values();
        $attribute2s = ProductModel::distinct()->pluck('attribute_2')->filter()->sort()->values();
        $attribute3s = ProductModel::distinct()->pluck('attribute_3')->filter()->sort()->values();
        
        // Get attribute labels
        $attributeLabels = [
            '1' => 'Category Level 1',
            '2' => 'Category Level 2',
            '3' => 'Category Level 3'
        ];
        
        return view('pages.products.create', compact(
            'productTypes',
            'vendors',
            'attribute1s',
            'attribute2s',
            'attribute3s',
            'attributeLabels'
        ));
    }

    /**
     * Rename a category and update all products and rules
     */
    public function renameCategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'attribute_key' => 'required|in:1,2,3',
                'old_name' => 'required|string',
                'new_name' => 'required|string|max:255'
            ]);
            
            $attributeKey = $validated['attribute_key'];
            $oldName = trim($validated['old_name']);
            $newName = trim($validated['new_name']);
            $column = 'attribute_' . $attributeKey;
            
            if ($oldName === $newName) {
                return response()->json([
                    'success' => false,
                    'message' => 'New name must be different from old name'
                ]);
            }
            
            // Check if new name already exists for other products
            $existingCount = \DB::table('t_crm_prod_product')
                ->where($column, $newName)
                ->count();
            
            if ($existingCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Category '{$newName}' already exists. This will merge {$existingCount} existing products with the renamed category."
                ]);
            }
            
            \DB::beginTransaction();
            
            try {
                // Update all products with old category name to new category name
                $updatedCount = \DB::table('t_crm_prod_product')
                    ->where($column, $oldName)
                    ->update([
                        $column => $newName,
                        'updated_at' => now()
                    ]);
                
                // Update rules in the JSON file
                $allRules = $this->readAttributeAutoRules();
                $rules = $allRules[(string)$attributeKey] ?? [];
                
                foreach ($rules as &$rule) {
                    if (isset($rule['group']) && $rule['group'] === $oldName) {
                        $rule['group'] = $newName;
                    }
                }
                
                $allRules[(string)$attributeKey] = $rules;
                $this->writeAttributeAutoRules($allRules);
                
                \DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => "Category renamed successfully. {$updatedCount} products updated.",
                    'products_updated' => $updatedCount
                ]);
                
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            \Log::error('Failed to rename category: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to rename category: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if SKU already exists (AJAX endpoint)
     */
    public function checkSku(Request $request)
    {
        $sku = trim($request->input('sku', ''));
        $variantId = $request->input('variant_id'); // For edit mode, exclude current variant
        
        if (empty($sku)) {
            return response()->json(['exists' => false]);
        }
        
        $query = \App\Models\CRM\ProductVariantModel::where('sku', $sku);
        
        if ($variantId) {
            $query->where('id', '!=', $variantId);
        }
        
        $exists = $query->exists();
        
        return response()->json([
            'exists' => $exists,
            'message' => $exists ? "SKU '{$sku}' is already used by another product" : "SKU is available"
        ]);
    }

    /**
     * Store a manually created product
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'attribute_1' => 'nullable|string|max:100',
                'attribute_2' => 'nullable|string|max:100',
                'attribute_3' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'nullable|boolean',
                'is_lean' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                
                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);
            
            // Check for duplicate SKUs
            foreach ($validated['variants'] as $index => $variant) {
                if (!empty($variant['sku'])) {
                    $existingSku = \App\Models\CRM\ProductVariantModel::where('sku', $variant['sku'])->first();
                    if ($existingSku) {
                        return back()->withInput()
                            ->withErrors(['variants.' . $index . '.sku' => "SKU '{$variant['sku']}' already exists in the system. Please use a unique SKU."])
                            ->with('error', "Duplicate SKU found: {$variant['sku']}");
                    }
                }
            }

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);
            
            // For single-variant products, default variant title to product title if empty
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }
            
            // Use the same function as API to maintain consistency
            $product = ProductModel::storeProductFromApi($productData);

            return redirect()->route('products.index')
                ->with('success', 'Product created successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error creating manual product: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to create product. Please try again.');
        }
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit($id)
    {
        try {
            $product = ProductModel::with('variants')->findOrFail($id);
            
            // Get distinct values for dropdowns (same as create page)
            $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort()->values();
            $vendors = ProductModel::distinct()->pluck('vendor')->filter()->sort()->values();
            $attribute1s = ProductModel::distinct()->pluck('attribute_1')->filter()->sort()->values();
            $attribute2s = ProductModel::distinct()->pluck('attribute_2')->filter()->sort()->values();
            $attribute3s = ProductModel::distinct()->pluck('attribute_3')->filter()->sort()->values();
            
            // Get attribute labels
            $attributeLabels = [
                '1' => 'Category Level 1',
                '2' => 'Category Level 2',
                '3' => 'Category Level 3'
            ];
            
            return view('pages.products.edit', compact(
                'product',
                'productTypes',
                'vendors',
                'attribute1s',
                'attribute2s',
                'attribute3s',
                'attributeLabels'
            ));
        } catch (\Exception $e) {
            Log::error('Error fetching product for edit: ' . $e->getMessage());
            return redirect()->route('products.index')
                ->with('error', 'Product not found.');
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        try {
            $product = ProductModel::findOrFail($id);

            // Allow editing for all products (manual or imported). No restrictions.

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'attribute_1' => 'nullable|string|max:100',
                'attribute_2' => 'nullable|string|max:100',
                'attribute_3' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'nullable|boolean',
                'is_lean' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                
                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.id' => 'nullable|integer|exists:t_crm_prod_product_variant,id',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);
            
            // Check for duplicate SKUs (excluding current product's variants)
            foreach ($validated['variants'] as $index => $variant) {
                if (!empty($variant['sku'])) {
                    $skuQuery = \App\Models\CRM\ProductVariantModel::where('sku', $variant['sku']);
                    
                    // Exclude current variant if it's being updated
                    if (isset($variant['id'])) {
                        $skuQuery->where('id', '!=', $variant['id']);
                    }
                    
                    $existingSku = $skuQuery->first();
                    if ($existingSku) {
                        return back()->withInput()
                            ->withErrors(['variants.' . $index . '.sku' => "SKU '{$variant['sku']}' already exists in another product. Please use a unique SKU."])
                            ->with('error', "Duplicate SKU found: {$variant['sku']}");
                    }
                }
            }

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);
            
            // For updates, we need to include the existing product ID
            $productData['existing_product_id'] = $product->id;
            
            // For updates, we need to include the existing product ID in variants
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    // If this is an existing variant, preserve its ID
                    if (isset($validated['variants'][$index]['id'])) {
                        $productData['variants'][$index]['id'] = $validated['variants'][$index]['id'];
                    }
                    
                    // For single-variant products, default variant title to product title if empty
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }
            
            // Use the same function as API to maintain consistency
            $updatedProduct = ProductModel::storeProductFromApi($productData);

            return redirect()->route('products.index')
                ->with('success', 'Product updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error updating manual product: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to update product. Please try again.');
        }
    }

    /**
     * Format manual product data to match API structure
     */
    private function formatManualProductData(array $validated): array
    {
        // Calculate price range from variants
        $prices = array_column($validated['variants'], 'price');
        $priceMin = min($prices);
        $priceMax = max($prices);
        
        // Calculate total inventory
        $totalInventory = array_sum(array_column($validated['variants'], 'inventory_quantity'));
        
        // Format variants to match API structure
        $variants = [];
        foreach ($validated['variants'] as $index => $variantData) {
            $variants[] = [
                'id' => $variantData['id'] ?? null, // For updates
                'title' => $variantData['title'],
                'sku' => $variantData['sku'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'price' => $variantData['price'],
                'compare_at_price' => $variantData['compare_at_price'] ?? null,
                'cost_price' => $variantData['cost_price'] ?? null,
                'inventory_quantity' => $variantData['inventory_quantity'],
                'inventory_policy' => 'deny', // Default for manual products
                'weight' => $variantData['weight'] ?? null,
                'weight_unit' => $variantData['weight_unit'] ?? 'g',
                'position' => $index + 1,
                'available' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Parse tags
        $tags = [];
        if (!empty($validated['tags'])) {
            $tags = array_map('trim', explode(',', $validated['tags']));
        }

        // Format data to match API structure
        return [
            // No Shopify IDs for manual products
            'shopify_product_id' => null,
            'shopify_handle' => null,
            
            // Basic info
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'vendor' => $validated['vendor'] ?? null,
            'product_type' => $validated['product_type'] ?? null,
            'attribute_1' => $validated['attribute_1'] ?? null,
            'attribute_2' => $validated['attribute_2'] ?? null,
            'attribute_3' => $validated['attribute_3'] ?? null,
            'status' => $validated['status'],
            'published_at' => $validated['status'] === 'active' ? now() : null,
            
            // Pricing
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            
            // Inventory
            'total_inventory' => $totalInventory,
            'track_inventory' => filter_var($validated['track_inventory'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_lean' => filter_var($validated['is_lean'] ?? false, FILTER_VALIDATE_BOOLEAN),
            
            // SEO
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            
            // Media (manual products start without images)
            'featured_image' => null,
            'images' => [],
            
            // Organization
            'tags' => $tags,
            'options' => [], // Manual products can have simple options later
            
            // Sync status (manual products are not synced)
            'sync_status' => 'manual',
            'last_synced_at' => null,
            'shopify_created_at' => null,
            'shopify_updated_at' => null,
            
            // Activity
            'is_active' => filter_var($validated['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            
            // Variants
            'variants' => $variants
        ];
    }
}
