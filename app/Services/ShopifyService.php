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
}
