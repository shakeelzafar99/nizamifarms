<?php

namespace App\Http\Controllers\FIN;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FIN\ArPaymentModel; 
use Illuminate\Support\Facades\Validator;


class ArPaymentController extends Controller
{
    protected $arpaymentModel;
    public function __construct(ArPaymentModel  $arpaymentModel)
    {
        $this->arpaymentModel = $arpaymentModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->arpaymentModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->arpaymentModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getdetail($id)
    {
        try {          
            $response = $this->arpaymentModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->arpaymentModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->arpaymentModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
