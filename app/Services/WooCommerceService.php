<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WooCommerceService
{
    protected string $baseUrl;
    protected string $consumerKey;
    protected string $consumerSecret;
    protected bool $verifySsl;

    public function __construct()
    {
        $this->baseUrl = config('woocommerce.base_url'); // e.g., https://example.com/wp-json/wc/v3
        $this->consumerKey = config('woocommerce.consumer_key');
        $this->consumerSecret = config('woocommerce.consumer_secret');
        $this->verifySsl = !app()->environment(['local', 'testing']);
    }

    /**
     * Fetch orders from WooCommerce between given dates
     */
    public function fetchOrders(string $fromDate, string $toDate): array
    {
        $orders = [];
        $page = 1;
        $totalFetched = 0;
        
        \Log::info('WooCommerce fetchOrders started', [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'base_url' => $this->baseUrl
        ]);
        
        do {
            $response = Http::withOptions(['verify' => $this->verifySsl])
                ->get($this->baseUrl . '/orders', [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'per_page' => 100,
                    'page' => $page,
                    'after' => (new \DateTime($fromDate))->format('Y-m-d\TH:i:s'),
                    'before' => (new \DateTime($toDate))->format('Y-m-d\TH:i:s'),
                    'status' => 'processing,on-hold,completed,cancelled,refunded,failed',
                ]);
                
            if ($response->failed()) {
                \Log::error('WooCommerce API request failed', [
                    'page' => $page,
                    'status_code' => $response->status(),
                    'response' => $response->body()
                ]);
                break;
            }
            
            $data = $response->json(); 
            $pageCount = count($data);
            $totalFetched += $pageCount;
            
            \Log::info('WooCommerce API page fetched', [
                'page' => $page,
                'orders_in_page' => $pageCount,
                'total_so_far' => $totalFetched
            ]);

            if (!empty($data)) {
                $orders = array_merge($orders, $data);
            }

            $page++;
        } while (!empty($data));

        \Log::info('WooCommerce fetchOrders completed', [
            'total_orders_fetched' => $totalFetched,
            'pages_processed' => $page - 1
        ]);

        return $orders;
    }

    /**
     * Fetch all products (paginated) from WooCommerce
     * API: GET /wp-json/wc/v3/products
     */
    public function fetchAllProducts(int $perPage = 100): array
    {
        $products = [];
        $page = 1;
        do {
            $response = Http::withOptions(['verify' => $this->verifySsl])
                ->get($this->baseUrl . '/products', [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'per_page' => $perPage,
                    'page' => $page,
                    'status' => 'publish',
                ]);
            if ($response->failed()) {
                \Log::error('WooCommerce fetchAllProducts failed', ['page' => $page, 'status' => $response->status(), 'body' => $response->body()]);
                break;
            }
            $data = $response->json();
            if (!empty($data)) {
                $products = array_merge($products, $data);
            }
            $page++;
        } while (!empty($data));
        return $products;
    }

    /**
     * Fetch variations for a variable product
     * API: GET /wp-json/wc/v3/products/{id}/variations
     */
    public function fetchProductVariations(int $productId, int $perPage = 100): array
    {
        $vars = [];
        $page = 1;
        do {
            $response = Http::withOptions(['verify' => $this->verifySsl])
                ->get($this->baseUrl . "/products/{$productId}/variations", [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);
            if ($response->failed()) {
                \Log::error('WooCommerce fetchProductVariations failed', ['product_id' => $productId, 'page' => $page, 'status' => $response->status(), 'body' => $response->body()]);
                break;
            }
            $data = $response->json();
            if (!empty($data)) {
                $vars = array_merge($vars, $data);
            }
            $page++;
        } while (!empty($data));
        return $vars;
    }

    /**
     * Map WooCommerce product to our canonical product payload
     */
    public function mapWooProduct(array $wooProduct, array $variations = []): array
    {
        $title = $wooProduct['name'] ?? '';
        $status = ($wooProduct['status'] ?? '') === 'publish' ? 'active' : 'inactive';
        $publishedAt = $wooProduct['date_created_gmt'] ?? null;
        $images = array_map(fn($img) => $img['src'] ?? null, $wooProduct['images'] ?? []);
        $featured = $images[0] ?? null;
        $tags = array_map(fn($t) => $t['name'] ?? null, $wooProduct['tags'] ?? []);
        $categories = array_map(fn($c) => $c['name'] ?? null, $wooProduct['categories'] ?? []);
        $productType = $categories[0] ?? ($wooProduct['type'] ?? 'standard');
        $attributes = $wooProduct['attributes'] ?? [];

        // Build options structure similar to Shopify
        $options = [];
        foreach ($attributes as $attr) {
            if (!empty($attr['name'])) {
                $values = [];
                if (isset($attr['options'])) {
                    if (is_array($attr['options'])) {
                        $values = array_map('trim', array_filter($attr['options']));
                    } else {
                        $values = array_map('trim', array_filter(explode('|', (string) $attr['options'])));
                    }
                }
                if (!empty($values)) {
                    $options[] = [
                        'name' => $attr['name'],
                        'values' => $values,
                    ];
                }
            }
        }

        // Variants
        $variantPayloads = [];
        if (($wooProduct['type'] ?? '') === 'variable') {
            foreach ($variations as $var) {
                $titleParts = [];
                foreach ($var['attributes'] ?? [] as $attr) {
                    if (!empty($attr['option'])) $titleParts[] = $attr['option'];
                }
                $variantPayloads[] = [
                    'id' => $var['id'] ?? null,
                    'sku' => $var['sku'] ?? null,
                    'title' => implode(' / ', $titleParts),
                    'price' => (float)($var['sale_price'] ?: ($var['regular_price'] ?? 0)),
                    'inventory_quantity' => (int)($var['stock_quantity'] ?? 0),
                    'available' => (bool)($var['in_stock'] ?? false),
                ];
            }
        } else {
            // simple product as single variant
            $variantPayloads[] = [
                'id' => $wooProduct['id'] ?? null,
                'sku' => $wooProduct['sku'] ?? null,
                'title' => '',
                'price' => (float)($wooProduct['sale_price'] ?: ($wooProduct['regular_price'] ?? 0)),
                'inventory_quantity' => (int)($wooProduct['stock_quantity'] ?? 0),
                'available' => (bool)($wooProduct['manage_stock'] ? ($wooProduct['stock_quantity'] ?? 0) > 0 : true),
            ];
        }

        // price_min / max and total inventory
        $prices = array_map(fn($v) => (float)$v['price'], $variantPayloads);
        $priceMin = !empty($prices) ? min($prices) : 0;
        $priceMax = !empty($prices) ? max($prices) : 0;
        $totalInventory = array_sum(array_map(fn($v) => (int)($v['inventory_quantity'] ?? 0), $variantPayloads));

        return [
            'external_source' => 'woocommerce',
            'external_id' => $wooProduct['id'] ?? null,
            'title' => $title,
            'vendor' => config('app.name'),
            'product_type' => $productType,
            'status' => $status,
            'published_at' => $publishedAt,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'total_inventory' => $totalInventory,
            'track_inventory' => (bool)($wooProduct['manage_stock'] ?? false),
            'featured_image' => $featured,
            'images' => $images,
            'tags' => $tags,
            'options' => $options,
            'variants' => $variantPayloads,
            'sync_status' => 'synced',
            'is_active' => 1,
        ];
    }
}
