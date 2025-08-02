<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FIN\Sys\SysArInvoiceModel;
use App\Models\SysAdmin\ConfigModel;
use PDF;

class InvoiceController extends Controller
{
    //

    public function invoice($id)
    {
        $model = new SysArInvoiceModel();

        //$inv = $model->Get($id);

        $config = new ConfigModel();
        $mygs = $config->GetById(); 
 
      
        $data[] = array(
            'MYG' => $mygs->toArray(),
            'INV' => "",
            'logo' => $mygs->logo,
            'inv_logo' => $mygs->inv_logo
        );
 

        //  $pdf = PDF::loadView('emails.myg.pdf.invoice', $data); 
        // return $pdf->download('invoice.pdf');
        return view('emails.myg.pdf.invoice', [
            'data' =>$data 
        ]);
    } 
    
}
