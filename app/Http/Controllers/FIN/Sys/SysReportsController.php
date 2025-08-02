<?php

namespace App\Http\Controllers\FIN\Sys;

use App\Http\Controllers\Controller;
use App\Models\FIN\Sys\SysReportsModel; 
use Illuminate\Http\Request;


class SysReportsController extends Controller
{

    protected $SysReportsModel; 
    public function __construct(SysReportsModel  $SysReportsModel)
    {
        $this->SysReportsModel = $SysReportsModel;
    }

    function cashup(Request $request)
    {
        try { 
            $response = $this->SysReportsModel->Cashup($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
