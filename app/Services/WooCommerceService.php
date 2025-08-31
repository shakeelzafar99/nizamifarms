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
        do {
            $response = Http::withOptions(['verify' => $this->verifySsl])
                ->get($this->baseUrl . '/orders', [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                    'per_page' => 100,
                    'page' => $page,
                    'after' => (new \DateTime($fromDate))->format('Y-m-d\TH:i:s'),
                    'before' => (new \DateTime($toDate))->format('Y-m-d\TH:i:s'),
                    'status' => 'processing,on-hold',
                ]);
            if ($response->failed()) {
                break;
            }
            $data = $response->json(); 

            if (!empty($data)) {
                $orders = array_merge($orders, $data);
            }

            $page++;
        } while (!empty($data));

        return $orders;
    }
}
