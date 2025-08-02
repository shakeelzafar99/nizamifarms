<?php

namespace App\Http\Controllers\HR;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\HR\InstituteModel;
use Illuminate\Support\Facades\Validator;

class InstituteController extends Controller
{

    protected $InstituteModel;
    public function __construct(InstituteModel  $InstituteModel)
    {
        $this->InstituteModel = $InstituteModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->InstituteModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->success($this->InstituteModel->Get($id));
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->InstituteModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->InstituteModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
 
}
