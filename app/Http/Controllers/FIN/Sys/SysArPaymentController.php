<?php

namespace App\Http\Controllers\FIN\Sys;

use App\Http\Controllers\Controller;
use App\Models\FIN\Sys\SysArPaymentModel;
use Illuminate\Http\Request;


class SysArPaymentController extends Controller
{

    protected $ArPaymentModel;
    public function __construct(SysArPaymentModel  $ArPaymentModel)
    {
        $this->ArPaymentModel = $ArPaymentModel;
    }

    function list(Request $request)
    {
        try {     
            $response = $this->ArPaymentModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->ArPaymentModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->ArPaymentModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->ArPaymentModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 

   
}
