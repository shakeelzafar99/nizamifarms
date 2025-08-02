<?php

namespace App\Http\Controllers\CRM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\AccountCustomerModel; 
use Illuminate\Support\Facades\Validator;


class AccountCustomerController extends Controller
{
    protected $customerModel;
    public function __construct(AccountCustomerModel  $customerModel)
    {
        $this->customerModel = $customerModel;
    }

    function autocomplete($company_id,$branch_id,$value, $value1, $value2)
    {
        try {      
            $response = $this->customerModel->Autocomplete($company_id,$branch_id,$value, $value1, $value2);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 

    function list(Request $request)
    {
        try {     
            $response = $this->customerModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }
 
    function get($id)
    {
        try {          
            $response = $this->customerModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->customerModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->customerModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
 
    
}
