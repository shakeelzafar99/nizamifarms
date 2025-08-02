<?php

namespace App\Http\Controllers\FIN;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FIN\ArInvoiceModel;  


class ArInvoiceController extends Controller
{
    protected $arinvoiceModel;
    public function __construct(ArInvoiceModel  $arinvoiceModel)
    {
        $this->arinvoiceModel = $arinvoiceModel;
    }
    function list(Request $request)
    {
        try {        
            $response = $this->arinvoiceModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

 
    function get($id)
    {
        try {          
            $response = $this->arinvoiceModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getdetail($id)
    {
        try {          
            $response = $this->arinvoiceModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getOutstanding($company_id,$branch_id,$supplier_id)
    {
        try {           
            $response = $this->arinvoiceModel->GetOutstanding($company_id,$branch_id,$supplier_id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->arinvoiceModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->arinvoiceModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
