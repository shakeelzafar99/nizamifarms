<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\ConfigModel;
use Illuminate\Http\Request;


class ConfigController extends Controller
{

    protected $configModel;
    public function __construct(ConfigModel  $configModel)
    {
        $this->configModel = $configModel;
    }

    function get($id)
    {
        try {
            $response = $this->configModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->configModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) { 
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

   
}
