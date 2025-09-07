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
            'status' => 'any', // Get all products regardless of status
            'published_status' => 'any' // Include published and unpublished
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
        
        do {
            $params = [
                'limit' => 250,
                'status' => 'any'
            ];
            
            if ($pageInfo) {
                $params['page_info'] = $pageInfo;
            }

            $response = Http::withBasicAuth($this->apiKey, $this->password)
                ->withOptions(['verify' => $this->verifySsl])
                ->get($baseUrl, $params);

            if ($response->failed()) {
                break;
            }

            $data = $response->json();
            if (!empty($data['products'])) {
                $allProducts = array_merge($allProducts, $data['products']);
            }

            // Get next page info from Link header
            $pageInfo = $this->extractPageInfo($response->header('Link'));
            
        } while ($pageInfo);

        return $allProducts;
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
