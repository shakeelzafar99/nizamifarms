<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\PaymentMethodModel;
use Illuminate\Http\Request;


class PaymentMethodController extends Controller
{

    protected $paymentMethodModel;
    public function __construct(PaymentMethodModel  $paymentMethodModel)
    {
        $this->paymentMethodModel = $paymentMethodModel;
    }

    function listByStatus($status)
    {
        try {     
            $response = $this->paymentMethodModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 
    function list(Request $request)
    {
        try {     
            $response = $this->paymentMethodModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function filter(Request $request)
    {
        try {     
            $response = $this->paymentMethodModel->Filter($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    

    function get($id)
    {
        try {          
            $response = $this->paymentMethodModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->paymentMethodModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->paymentMethodModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 

   
}
