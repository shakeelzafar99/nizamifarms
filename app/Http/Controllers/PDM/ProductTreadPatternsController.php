<?php

namespace App\Http\Controllers\PDM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\PDM\ProductTreadPatternsModel;
use Illuminate\Support\Facades\Validator;


class ProductTreadPatternsController extends Controller
{
    protected $productTreadPatternsModel;
    public function __construct(ProductTreadPatternsModel  $productTreadPatternsModel)
    {
        $this->productTreadPatternsModel = $productTreadPatternsModel;
    } 
    
    function autocomplete($company_id,$branch_id,$value, $value1, $value2)
    {
        try {    
            $response = $this->productTreadPatternsModel->Autocomplete($company_id,$branch_id,$value, $value1, $value2); 
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 

    function list(Request $request)
    {
        try {
            $response = $this->productTreadPatternsModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function get($id)
    {

        try {
            $response = $this->productTreadPatternsModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {
            $response = $this->productTreadPatternsModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->productTreadPatternsModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
