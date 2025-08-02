<?php

namespace App\Http\Controllers\SCM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SCM\StockModel;
use Illuminate\Support\Facades\Validator;


class StockController extends Controller
{
    protected $stockModel;
    public function __construct(StockModel  $stockModel)
    {
        $this->stockModel = $stockModel;
    }
    function productList(Request $request)
    {

        try {
            $response = $this->stockModel->ProductList($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function partList(Request $request)
    {
        try {
            $response = $this->stockModel->PartList($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {
        try {
            $response = $this->stockModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function store(Request $request) //ADD   
    {
        try { 
            $response = $this->stockModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->stockModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
