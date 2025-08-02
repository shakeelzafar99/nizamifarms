<?php

namespace App\Http\Controllers\FIN;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FIN\ApInvoiceModel;  


class ApInvoiceController extends Controller
{
    protected $apinvoiceModel;
    public function __construct(ApInvoiceModel  $apinvoiceModel)
    {
        $this->apinvoiceModel = $apinvoiceModel;
    }
    function list(Request $request)
    {
        try {        
            $response = $this->apinvoiceModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

 
    function get($id)
    {
        try {          
            $response = $this->apinvoiceModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getdetail($id)
    {
        try {          
            $response = $this->apinvoiceModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function getOutstanding($company_id,$branch_id,$supplier_id)
    {
        try {           
            $response = $this->apinvoiceModel->GetOutstanding($company_id,$branch_id,$supplier_id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->apinvoiceModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->apinvoiceModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
