<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\UserModel;
use Illuminate\Http\Request;


class UserController extends Controller
{

    protected $userModel;
    public function __construct(UserModel  $userModel)
    {
        $this->userModel = $userModel;
    }

    public function change_password(Request $request)
    {
        try {     
            $response = $this->userModel->change_password($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function list(Request $request)
    {
        try {     
            $response = $this->userModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->userModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->userModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->userModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 

   
}
