<?php

namespace App\Http\Controllers\PDM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\SizeModel;
use Illuminate\Support\Facades\Validator;

class SizeController extends Controller
{

    protected $sizeModel;
    public function __construct(SizeModel  $sizeModel)
    {
        $this->sizeModel = $sizeModel;
    }

    function listByStatus($status)
    {
        try {     
            $response = $this->sizeModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 

    function autocomplete($value)
    {
        try {
            $response = $this->sizeModel->Autocomplete($value);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function list(Request $request)
    {
        try {     
            $response = $this->sizeModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->sizeModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->sizeModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
    
        try {          
            $id = $request->id; 
            $response = $this->sizeModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
 
}
