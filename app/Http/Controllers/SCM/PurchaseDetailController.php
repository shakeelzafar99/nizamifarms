<?php

namespace App\Http\Controllers\SCM;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\SCM\PurchaseDetailModel; 


class PurchaseDetailController extends Controller
{

    protected $purchaseDetailModel;
    public function __construct(PurchaseDetailModel  $purchaseDetailModel)
    {
        $this->purchaseDetailModel = $purchaseDetailModel;
    }
    
    function get($id)
    {
        
        try {          
            $response = $this->purchaseDetailModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(),$e->getCode());
        }  
    }

    
 

    
}
