<?php

namespace App\Http\Controllers\SysAdmin;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SysAdmin\LovModel; 



class LovController extends Controller
{

    function list(Request $request)
    { 
        $group = $request->group;
        $lovModel = new LovModel();
        $data = $lovModel->List($group);    
        return response()->json($data);
    } 
    
}
