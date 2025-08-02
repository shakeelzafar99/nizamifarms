<?php

namespace App\Http\Controllers\FIN;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FIN\ApPaymentModel; 
use Illuminate\Support\Facades\Validator;


class ApPaymentController extends Controller
{
    protected $appaymentModel;
    public function __construct(ApPaymentModel  $appaymentModel)
    {
        $this->appaymentModel = $appaymentModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->appaymentModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->appaymentModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getdetail($id)
    {
        try {          
            $response = $this->appaymentModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->appaymentModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->appaymentModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
