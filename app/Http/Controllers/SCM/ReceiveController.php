<?php

namespace App\Http\Controllers\SCM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SCM\ReceiveModel; 


class ReceiveController extends Controller
{

    protected $receiveModel;
    public function __construct(ReceiveModel  $receiveModel)
    {
        $this->receiveModel = $receiveModel;
    }
    function list(Request $request)
    {
        try {     
            $response = $this->receiveModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        
        try {          
            $response = $this->receiveModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->receiveModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->receiveModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 
 

    
}
