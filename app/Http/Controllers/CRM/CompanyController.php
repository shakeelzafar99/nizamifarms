<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\CompanyModel; 

class CompanyController extends Controller
{

    protected $companyModel;
    public function __construct(CompanyModel  $companyModel)
    {
        $this->companyModel = $companyModel;
    }

    function listByStatus($status)
    {
        try {
            $response = $this->companyModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function list(Request $request)
    {
        try {
            $response = $this->companyModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {
        try {
            $response = $this->companyModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {  
            $response = $this->companyModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), "00");
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->companyModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
