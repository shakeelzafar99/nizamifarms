<?php 
namespace App\Traits\FIN;
use App\Models\FIN\ApInvoiceModel;
use App\Models\FIN\ApPaymentModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\BranchModel;
use App\Models\SCM\PurchaseModel;
use Carbon\Carbon;

trait ApTransaction{ 

    protected array $header = [];
    protected array $details = [];
    protected array $paymentSource = []; 
    protected array $transData = []; 
    protected int $cid = 0;
    protected int $bid = 0;

 
    protected function apInvoice()
	{
        try {            
            $apInvoice = new ApInvoiceModel();
            $this->transData = $this->header;
            $this->transData["ApInvoiceDetailModel"] = $this->details;   
            $response = $apInvoice->Create($this->transData);   
        } catch (\Exception $e) {
            return 0; 
        }     
    }
    
    protected function apPayment()
	{
        try {         

            $apPayment = new ApPaymentModel();
            $this->transData = $this->header;
            $this->transData["ApPaymentDetailModel"] = $this->details; 
            $this->transData["ApPaymentSourceModel"] = $this->paymentSource;  
            $response = $apPayment->Create($this->transData); 
         
        } catch (\Exception $e) {
            return 0; 
        }     
	} 

    protected function getApPoNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid);  
            $inv = new PurchaseModel();
            $invoice = $inv->GetCountByBranch($this->bid);
            $year = Carbon::now()->format('y');
            $month = strtoupper(Carbon::now()->format('M')); 
            $this->newNum = "PO/" . strtoupper($com->data["prefix"]) ."/". str_pad($month, 2, '0', STR_PAD_LEFT)  . $year ."/". str_pad($invoice + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

    protected function getApPaymentNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid);             

            $pymt = new ApPaymentModel();
            $payment = $pymt->GetCountByBranch($this->bid);
            

            $this->newNum = "PYMT/" . strtoupper($com->data["prefix"]) . str_pad($payment + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

}