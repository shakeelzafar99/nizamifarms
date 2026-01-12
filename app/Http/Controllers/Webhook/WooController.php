<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class WooController extends Controller
{
    /**
     * ⚠️ WOOCOMMERCE INTEGRATION DISABLED
     * 
     * All WooCommerce sync operations have been permanently disabled.
     * Products are now managed exclusively through web UI and mobile app.
     * This prevents accidental data overwrites and preserves SKU/category data.
     * 
     * Disabled on: December 2024
     */
    private const DISABLED_MESSAGE = 'WooCommerce integration has been permanently disabled. Products are managed via web UI and mobile app only.';


    protected $orderModel;
    public function __construct(OrderModel  $orderModel)
    {
        $this->orderModel = $orderModel;
    }


    function createLog($data, $fileType, $directory, $filename)
    {
        if ($fileType === "json") { // Encode combined data to pretty JSON
            $data = json_encode($data, JSON_PRETTY_PRINT);
        }
        // Filename with timestamp
        $filename = 'shopify/' . $directory . '/' . $filename . '-' . now()->format('Y_m_d_His') . '.' . $fileType;
        // Save to storage/app/shopify_requests/
        Storage::disk('public')->put($filename, $data);
    }

    function test(Request $request) // TEST endpoint for debugging
    {
        Log::warning('WooCommerce test endpoint called but is DISABLED');
        return response()->json([
            'error' => self::DISABLED_MESSAGE,
            'status' => 'disabled'
        ], 403);
    }

    function store(Request $request) //ADD   
    {
        // ⚠️ WOOCOMMERCE WEBHOOK DISABLED - Log attempts for security monitoring
        Log::warning('WooCommerce store webhook called but is DISABLED', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'content_length' => strlen($request->getContent())
        ]);
        
        // Log the attempt to file for audit trail
        $this->createLog([
            'message' => 'BLOCKED: WooCommerce webhook attempted',
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
        ], "json", "blocked", "woo_blocked");
        
        return response()->json([
            'error' => self::DISABLED_MESSAGE,
            'status' => 'disabled',
            'action' => 'no_changes_made'
        ], 403);
    }

    function remove(Request $request) //DELETE
    {
        Log::warning('WooCommerce remove endpoint called but is DISABLED', ['id' => $request->id]);
        return response()->json(['error' => self::DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }
    
    function get($id) 
    {
        Log::warning('WooCommerce get endpoint called but is DISABLED', ['id' => $id]);
        return response()->json(['error' => self::DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }
    
    function list(Request $request)
    {
        Log::warning('WooCommerce list endpoint called but is DISABLED');
        return response()->json(['error' => self::DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }
}
