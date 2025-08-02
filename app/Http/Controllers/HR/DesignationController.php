<?php

namespace App\Http\Controllers\HR;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\HR\DesignationModel;
use Illuminate\Support\Facades\Validator;

class DesignationController extends Controller
{

    protected $designationModel;
    public function __construct(DesignationModel  $designationModel)
    {
        $this->designationModel = $designationModel;
    }
    function list(Request $request)
    {
        try {
            $response =  $this->designationModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {
        try {
            $response = $this->success($this->designationModel->Get($id));
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {
            $response =  $this->designationModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response =  $this->designationModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
