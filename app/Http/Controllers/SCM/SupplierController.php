<?php

namespace App\Http\Controllers\SCM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SCM\SupplierModel; 
use Illuminate\Support\Facades\Validator;


class SupplierController extends Controller
{

    protected $supplierModel;
    public function __construct(SupplierModel  $supplierModel)
    {
        $this->supplierModel = $supplierModel;
    }


    function listByStatus($status)
    {
        try {     
            $response = $this->supplierModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 


    function list(Request $request)
    {
        try {     
            $response = $this->supplierModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->supplierModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->supplierModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->supplierModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
}
