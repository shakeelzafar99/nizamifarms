<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Validator;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{


    protected $orderModel;
    protected ShopifyService $shopify;
    public function __construct(OrderModel  $orderModel, ShopifyService $shopify)
    {
        $this->orderModel = $orderModel;
        $this->shopify = $shopify;
    }

    public function index()
    {
        // Get orders with pagination (10 per page)
        $orders = OrderModel::orderBy('created_at', 'desc')->paginate(10);

        // Pass orders to the view
        return view('pages.orders.index', compact('orders'));
    }


    public function importShopify(Request $request)
    {
        try {
            // ✅ Step 1: Validate inputs
            $validated = $request->validate([
                'from_date' => 'required|date',
                'to_date'   => 'required|date|after_or_equal:from_date',
            ]);

            // ✅ Step 2: Fetch orders from Shopify Service
            $orders = $this->shopify->fetchOrders($validated['from_date'], $validated['to_date']);

            if (empty($orders)) {
                return redirect()->back()->with('warning', 'No orders found for the selected date range.');
            }

            // ✅ Step 3: Store orders in DB
            $orderModel = new OrderModel();
            foreach ($orders as $order) {
                $order['source'] = 'shopify';
                $order['shopify_id'] = $order["id"];
                $orderModel->store($order); // Assuming you already have a "store" method
            }

            // ✅ Step 4: Return success
            return redirect()->back()->with('success', count($orders) . ' Shopify orders imported successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            dd(" Validation errors" . $e->errors());
            // Validation errors (handled automatically but you can customize)
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Shopify API request failure
            dd("Shopify API request failure" . $e->getMessage());
            Log::error('Shopify API Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to connect to Shopify API. Please try again.');
        } catch (\Exception $e) {
            dd("Catch-all for unexpected errors" . $e->getMessage());
            // Catch-all for unexpected errors
            Log::error('Shopify Import Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Something went wrong while importing orders.');
        }
    }



    function list(Request $request)
    {
        try {
            $response = $this->orderModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function getdetail($id)
    {

        try {
            $response = $this->orderModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function get($id)
    {
        try {
            $response = $this->orderModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {
            $response = $this->orderModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->orderModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
