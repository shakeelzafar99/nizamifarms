<?php

namespace App\Http\Controllers\SCM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SCM\PurchaseModel; 


class PurchaseController extends Controller
{

    protected $purchaseModel;
    public function __construct(PurchaseModel  $purchaseModel)
    {
        $this->purchaseModel = $purchaseModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->purchaseModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    
      
    function getdetail($id)
    {      
        try {        
            $response = $this->purchaseModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function get($id)
    {
        
        try {          
            $response = $this->purchaseModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {        
            $response = $this->purchaseModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->purchaseModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
 

    
}
