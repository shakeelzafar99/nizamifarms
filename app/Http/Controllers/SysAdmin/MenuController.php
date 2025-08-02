<?php

namespace App\Http\Controllers\SysAdmin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\MenuModel;


class MenuController extends Controller
{
    protected $menuModel;
    public function __construct(MenuModel  $model)
    {
        $this->menuModel = $model;
    }

    function tree($roleId)
    {
        try {
            $response = $this->menuModel->Tree($roleId);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function navtree()
    {
        try {
            $response = $this->menuModel->Navtree();
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
    function permission(Request $request)
    {
        try {
            $response = $this->menuModel->Permission($request);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function list(Request $request)
    {
        try {
            $response = $this->menuModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
    function get($id)
    {
        try {
            $response = $this->menuModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function store(Request $request) //ADD   
    {
        try {
            $response = $this->menuModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->menuModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
