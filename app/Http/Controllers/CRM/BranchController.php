<?php

namespace App\Http\Controllers\CRM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\BranchModel; 
use Illuminate\Support\Facades\Validator;


class BranchController extends Controller
{

    protected $branchModel;
    public function __construct(BranchModel  $branchModel)
    {
        $this->branchModel = $branchModel;
    }

    function listByStatus($status)
    {
        try {     
            $response = $this->branchModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 
    function list(Request $request)
    {
        try {     
            $response = $this->branchModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function filter(Request $request)
    {
        try {     
            $response = $this->branchModel->Filter($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->branchModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {      
            $response = $this->branchModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->branchModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 
    
}
