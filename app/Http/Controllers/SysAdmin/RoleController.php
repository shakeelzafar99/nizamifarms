<?php

namespace App\Http\Controllers\SysAdmin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\RoleModel;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{

    protected $roleModel;
    public function __construct(RoleModel  $roleModel)
    {
        $this->roleModel = $roleModel;
    }
    function list(Request $request)
    {
        try {
            $response = $this->roleModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {
        try {
            $response = $this->roleModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try { 
            $response = $this->roleModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->roleModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
