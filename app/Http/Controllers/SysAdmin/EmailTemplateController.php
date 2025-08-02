<?php

namespace App\Http\Controllers\SysAdmin;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\EmailTemplateModel;
use Illuminate\Http\Request;


class EmailTemplateController extends Controller
{

    protected $emailTemplateModel;
    public function __construct(EmailTemplateModel  $emailTemplateModel)
    {
        $this->emailTemplateModel = $emailTemplateModel;
    }
    function list(Request $request)
    {
        try {      
            $response = $this->emailTemplateModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }
    }

    function get($id)
    {
        try {          
            $response = $this->emailTemplateModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    function store(Request $request) //ADD   
    {     
        try {            
            $response = $this->emailTemplateModel->Store($request->all()); 
        return $this->success($response);
        } catch (\Exception $e) { 
            return $this->error($e->getMessage(),$e->getCode());
        }  
    } 

    function remove(Request $request) //DELETE
    {
        try {          
            $id = $request->id; 
            $response = $this->emailTemplateModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        } 

    } 

   
}
