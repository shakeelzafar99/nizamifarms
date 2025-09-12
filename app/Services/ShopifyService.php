<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyService
{
    protected string $apiKey;
    protected string $password;
    protected string $storeName;
    protected string $apiVersion;
    protected bool $verifySsl;
    public function __construct()
    {
        $this->apiKey = config('shopify.api_key');
        $this->password = config('shopify.password');
        $this->storeName = config('shopify.store_name');
        $this->apiVersion = config('shopify.api_version');
        $this->verifySsl = !app()->environment(['local', 'testing']);
    }

    /**
     * Fetch orders from Shopify between given dates
     */
    public function fetchOrders(string $fromDate, string $toDate): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/orders.json";

        $orders = [];
        $dateRange = 7; // days per request
        $startDate = new \DateTime($fromDate);
        $endDate = new \DateTime($toDate);

        while ($startDate <= $endDate) {
            $nextDate = clone $startDate;
            $nextDate->modify("+{$dateRange} days");

            $response = Http::withBasicAuth($this->apiKey, $this->password)
                ->withOptions(['verify' => $this->verifySsl]) // ✅ Fix SSL issue    
                ->get($baseUrl, [
                    'limit' => 250,
                    'created_at_min' => $startDate->format('Y-m-d\TH:i:s'),
                    'created_at_max' => $nextDate->format('Y-m-d\TH:i:s'),
                ]);

            if ($response->failed()) {
                break; // stop on error
            }

            $data = $response->json();
            if (!empty($data['orders'])) {
                $orders = array_merge($orders, $data['orders']);
            }

            $startDate = $nextDate;
        }

        return $orders;
    }

    /**
     * Fetch products from Shopify
     */
    public function fetchProducts(int $limit = 50): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/products.json";

        $params = [
            'limit' => min($limit, 250), // Shopify max is 250
            'status' => 'active', // Get active products
            'published_status' => 'published' // Get published products
        ];
        
        // Log the request for debugging
        \Log::info('Shopify Products API Request', [
            'url' => $baseUrl,
            'params' => $params,
            'api_key_set' => !empty($this->apiKey),
            'password_set' => !empty($this->password),
            'store_name' => $this->storeName
        ]);
        
        $response = Http::withBasicAuth($this->apiKey, $this->password)
            ->withOptions(['verify' => $this->verifySsl])
            ->timeout(30) // Add 30 second timeout
            ->get($baseUrl, $params);

        if ($response->failed()) {
            $errorBody = $response->body();
            $statusCode = $response->status();
            throw new \Exception("Failed to fetch products from Shopify (HTTP {$statusCode}): {$errorBody}");
        }

        $data = $response->json();
        
        // Log the response for debugging
        \Log::info('Shopify Products API Response', [
            'url' => $baseUrl,
            'limit' => $limit,
            'status_code' => $response->status(),
            'products_count' => count($data['products'] ?? []),
            'response_keys' => array_keys($data),
            'full_response' => $data // Log full response to see what we're getting
        ]);
        
        return $data['products'] ?? [];
    }

    /**
     * Fetch single product from Shopify
     */
    public function fetchProduct(int $productId): ?array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/products/{$productId}.json";

        $response = Http::withBasicAuth($this->apiKey, $this->password)
            ->withOptions(['verify' => $this->verifySsl])
            ->get($baseUrl);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        return $data['product'] ?? null;
    }

    /**
     * Fetch all products with pagination
     */
    public function fetchAllProducts(): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/products.json";
        
        $allProducts = [];
        $pageInfo = null;
        $pageCount = 0;
        
        \Log::info('Starting to fetch all products from Shopify', [
            'api_version' => $this->apiVersion,
            'store_name' => $this->storeName
        ]);
        
        do {
            $pageCount++;
            $params = [
                'limit' => 250,
                'status' => 'active',
                'published_status' => 'published'
            ];
            
            if ($pageInfo) {
                $params['page_info'] = $pageInfo;
            }

            \Log::info("Fetching products page {$pageCount}", ['params' => $params]);

            $response = Http::withBasicAuth($this->apiKey, $this->password)
                ->withOptions(['verify' => $this->verifySsl])
                ->timeout(60) // Longer timeout for bulk operations
                ->get($baseUrl, $params);

            if ($response->failed()) {
                \Log::error("Failed to fetch products page {$pageCount}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                break;
            }

            $data = $response->json();
            if (!empty($data['products'])) {
                $allProducts = array_merge($allProducts, $data['products']);
                \Log::info("Fetched " . count($data['products']) . " products from page {$pageCount}");
            }

            // Get next page info from Link header
            $pageInfo = $this->extractPageInfo($response->header('Link'));
            
        } while ($pageInfo);

        \Log::info('Completed fetching all products', [
            'total_products' => count($allProducts),
            'pages_fetched' => $pageCount
        ]);

        return $allProducts;
    }

    /**
     * Fetch price rules (coupons/discounts) from Shopify
     */
    public function fetchPriceRules(int $limit = 50): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/price_rules.json";

        $params = [
            'limit' => min($limit, 250), // Shopify max is 250
        ];
        
        // Log the request for debugging
        \Log::info('Shopify Price Rules API Request', [
            'url' => $baseUrl,
            'params' => $params,
            'api_key_set' => !empty($this->apiKey),
            'password_set' => !empty($this->password),
            'store_name' => $this->storeName
        ]);
        
        $response = Http::withBasicAuth($this->apiKey, $this->password)
            ->withOptions(['verify' => $this->verifySsl])
            ->timeout(30) // Add 30 second timeout
            ->get($baseUrl, $params);

        if ($response->failed()) {
            $errorBody = $response->body();
            $statusCode = $response->status();
            throw new \Exception("Failed to fetch price rules from Shopify (HTTP {$statusCode}): {$errorBody}");
        }

        $data = $response->json();
        
        // Log the response for debugging
        \Log::info('Shopify Price Rules API Response', [
            'url' => $baseUrl,
            'limit' => $limit,
            'status_code' => $response->status(),
            'price_rules_count' => count($data['price_rules'] ?? []),
            'response_keys' => array_keys($data)
        ]);
        
        return $data['price_rules'] ?? [];
    }

    /**
     * Fetch discount codes for a specific price rule
     */
    public function fetchDiscountCodes(int $priceRuleId): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/price_rules/{$priceRuleId}/discount_codes.json";
        
        // Log the request for debugging
        \Log::info('Shopify Discount Codes API Request', [
            'url' => $baseUrl,
            'price_rule_id' => $priceRuleId
        ]);
        
        $response = Http::withBasicAuth($this->apiKey, $this->password)
            ->withOptions(['verify' => $this->verifySsl])
            ->timeout(30)
            ->get($baseUrl);

        if ($response->failed()) {
            $errorBody = $response->body();
            $statusCode = $response->status();
            \Log::warning("Failed to fetch discount codes for price rule {$priceRuleId} (HTTP {$statusCode}): {$errorBody}");
            return [];
        }

        $data = $response->json();
        
        // Log the response for debugging
        \Log::info('Shopify Discount Codes API Response', [
            'price_rule_id' => $priceRuleId,
            'discount_codes_count' => count($data['discount_codes'] ?? [])
        ]);
        
        return $data['discount_codes'] ?? [];
    }

    /**
     * Fetch all price rules with their discount codes
     */
    public function fetchAllPriceRulesWithCodes(): array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/price_rules.json";
        
        $allPriceRules = [];
        $pageInfo = null;
        $pageCount = 0;
        
        \Log::info('Starting to fetch all price rules from Shopify', [
            'api_version' => $this->apiVersion,
            'store_name' => $this->storeName
        ]);
        
        do {
            $pageCount++;
            $params = [
                'limit' => 250,
            ];
            
            if ($pageInfo) {
                $params['page_info'] = $pageInfo;
            }

            \Log::info("Fetching price rules page {$pageCount}", ['params' => $params]);

            $response = Http::withBasicAuth($this->apiKey, $this->password)
                ->withOptions(['verify' => $this->verifySsl])
                ->timeout(60) // Longer timeout for bulk operations
                ->get($baseUrl, $params);

            if ($response->failed()) {
                \Log::error("Failed to fetch price rules page {$pageCount}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                break;
            }

            $data = $response->json();
            if (!empty($data['price_rules'])) {
                // For each price rule, fetch its discount codes
                foreach ($data['price_rules'] as $priceRule) {
                    try {
                        $discountCodes = $this->fetchDiscountCodes($priceRule['id']);
                        $priceRule['discount_codes'] = $discountCodes;
                        $allPriceRules[] = $priceRule;
                    } catch (\Exception $e) {
                        \Log::warning("Failed to fetch discount codes for price rule {$priceRule['id']}: " . $e->getMessage());
                        // Still add the price rule without discount codes
                        $priceRule['discount_codes'] = [];
                        $allPriceRules[] = $priceRule;
                    }
                }
                
                \Log::info("Fetched " . count($data['price_rules']) . " price rules from page {$pageCount}");
            }

            // Get next page info from Link header
            $pageInfo = $this->extractPageInfo($response->header('Link'));
            
        } while ($pageInfo);

        \Log::info('Completed fetching all price rules', [
            'total_price_rules' => count($allPriceRules),
            'pages_fetched' => $pageCount
        ]);

        return $allPriceRules;
    }

    /**
     * Fetch single price rule from Shopify
     */
    public function fetchPriceRule(int $priceRuleId): ?array
    {
        $baseUrl = "https://{$this->storeName}.myshopify.com/admin/api/{$this->apiVersion}/price_rules/{$priceRuleId}.json";

        $response = Http::withBasicAuth($this->apiKey, $this->password)
            ->withOptions(['verify' => $this->verifySsl])
            ->get($baseUrl);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        $priceRule = $data['price_rule'] ?? null;
        
        if ($priceRule) {
            // Fetch discount codes for this price rule
            try {
                $discountCodes = $this->fetchDiscountCodes($priceRuleId);
                $priceRule['discount_codes'] = $discountCodes;
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch discount codes for price rule {$priceRuleId}: " . $e->getMessage());
                $priceRule['discount_codes'] = [];
            }
        }
        
        return $priceRule;
    }

    /**
     * Extract page info from Shopify Link header
     */
    private function extractPageInfo(?string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        // Look for next page link
        if (preg_match('/<[^>]*page_info=([^>&]+)[^>]*>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
