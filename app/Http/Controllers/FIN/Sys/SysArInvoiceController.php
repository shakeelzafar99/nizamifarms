<?php

namespace App\Http\Controllers\FIN\Sys;
use App\Http\Controllers\Controller;
use App\Models\FIN\Sys\SysArInvoiceModel;
use Illuminate\Http\Request;


class SysArInvoiceController extends Controller
{

    protected $ArInvoiceModel;
    public function __construct(SysArInvoiceModel  $ArInvoiceModel)
    {
        $this->ArInvoiceModel = $ArInvoiceModel;
    }

    function list(Request $request)
    {
        try {     
            $response = $this->ArInvoiceModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->ArInvoiceModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function email($id)
    {
        try {          
            $response = $this->ArInvoiceModel->Email($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->ArInvoiceModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->ArInvoiceModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 

   
}
