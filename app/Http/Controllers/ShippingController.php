<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ShippingController extends Controller
{
    /**
     * Display the shipping configuration page
     */
    public function index()
    {
        $shippingPrice = Cache::get('shipping_price', 0);
        return view('pages.shipping.index', compact('shippingPrice'));
    }

    /**
     * Update the shipping price
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'shipping_price' => 'required|numeric|min:0'
        ]);

        // Store in cache (persists across sessions)
        Cache::forever('shipping_price', $validated['shipping_price']);

        return response()->json([
            'success' => true,
            'message' => 'Shipping price updated successfully',
            'shipping_price' => $validated['shipping_price']
        ]);
    }

    /**
     * Get current shipping price (API endpoint)
     */
    public function getPrice()
    {
        $shippingPrice = Cache::get('shipping_price', 0);
        
        return response()->json([
            'success' => true,
            'shipping_price' => $shippingPrice
        ]);
    }
}
