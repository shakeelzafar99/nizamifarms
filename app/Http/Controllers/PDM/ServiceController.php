<?php

namespace App\Http\Controllers\PDM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\ServiceModel; 
use Illuminate\Support\Facades\Validator;


class ServiceController extends Controller
{

    protected $serviceModel;
    public function __construct(ServiceModel  $serviceModel)
    {
        $this->serviceModel = $serviceModel;
    }

    function autocomplete($company_id,$branch_id,$value, $value1, $value2)
    {
        try {      
            $response = $this->serviceModel->Autocomplete($company_id,$branch_id,$value, $value1, $value2);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 


    function list(Request $request)
    {
        try {     
            $response = $this->serviceModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->serviceModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->serviceModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->serviceModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
}
