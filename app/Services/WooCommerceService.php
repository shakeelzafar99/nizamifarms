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
}
