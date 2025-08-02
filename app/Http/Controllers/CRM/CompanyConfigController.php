<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\CompanyConfigModel; 

class CompanyConfigController extends Controller
{

    protected $companyConfigModel;
    public function __construct(CompanyConfigModel  $companyConfigModel)
    {
        $this->companyConfigModel = $companyConfigModel;
    }

    function listByStatus($status)
    {
        try {
            $response = $this->companyConfigModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function list(Request $request)
    {
        try {
            $response = $this->companyConfigModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {
        try { 
            $response = $this->companyConfigModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {  
            $response = $this->companyConfigModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), "00");
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->companyConfigModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
