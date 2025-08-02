<?php

namespace App\Http\Controllers\SysAdmin;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\PackageModel; 
 
class PackageController extends Controller
{

    protected $packageModel;
    public function __construct(PackageModel  $packageModel)
    {
        $this->packageModel = $packageModel;
    }


    function listByStatus($status)
    {
        try {     
            $response = $this->packageModel->ListByStatus($status);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    } 

    function list(Request $request)
    {
        try {     
            $response = $this->packageModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->packageModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->packageModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->packageModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

        
    } 

}
