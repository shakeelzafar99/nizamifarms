<?php

namespace App\Http\Controllers\PDM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\BrandModel; 
use Illuminate\Support\Facades\Validator;


class BrandController extends Controller
{
    protected $brandModel;
    public function __construct(BrandModel  $brandModel)
    {
        $this->brandModel = $brandModel;
    }
    function listByStatus($status)
    {
        try {     
            $response = $this->brandModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 
    function list(Request $request)
    {
        try {     
            $response = $this->brandModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->brandModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->brandModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->brandModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
