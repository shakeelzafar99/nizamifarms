<?php

namespace App\Http\Controllers\FIN;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FIN\GlModel; 
use Illuminate\Support\Facades\Validator;


class GlController extends Controller
{
    protected $glModel;
    public function __construct(GlModel  $glModel)
    {
        $this->glModel = $glModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->glModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->glModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {          
            $response = $this->glModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->glModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
    
}
