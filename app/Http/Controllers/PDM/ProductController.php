<?php

namespace App\Http\Controllers\PDM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\ProductModel;
use Illuminate\Support\Facades\Validator;


class ProductController extends Controller
{
    protected $productModel;
    public function __construct(ProductModel  $productModel)
    {
        $this->productModel = $productModel;
    }


    function autocomplete($value, $value1)
    {
        try {
            $response = $this->productModel->Autocomplete($value, $value1);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function list(Request $request)
    {
        try {
            $response = $this->productModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {

        try {
            $response = $this->productModel->Get($id, 0, 0);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
    function getBrandSize($brand_id, $size_id)
    {

        try {
            $response = $this->productModel->Get(0, $brand_id, $size_id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }





    function store(Request $request) //ADD   
    {
        try {
            $response = $this->productModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->productModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
