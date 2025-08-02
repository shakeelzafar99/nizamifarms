<?php

namespace App\Http\Controllers\PDM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\PartModel; 
use Illuminate\Support\Facades\Validator;


class PartController extends Controller
{ protected $partModel;
    public function __construct(PartModel  $partModel)
    {
        $this->partModel = $partModel;
    }
    

    function autocomplete($company_id,$branch_id,$value, $value1, $value2)
    {
        try {      
            $response = $this->partModel->Autocomplete($company_id,$branch_id,$value, $value1, $value2);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 


    function list(Request $request)
    {
        try {     
            $response = $this->partModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 

    function get($id){
    
    try {          
        $response = $this->partModel->Get($id);
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->partModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->partModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    }

    
}
