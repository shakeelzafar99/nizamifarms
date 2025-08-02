<?php 
namespace App\Traits; 
use App\Models\CRM\CompanyModel;
use App\Models\CRM\BranchModel; 
use App\Models\CRM\AccountCustomerModel; 
use Carbon\Carbon;

trait Common{ 

    protected array $header = [];
    protected array $details = [];
    protected array $paymentSource = []; 
    protected array $transData = []; 
    protected int $cid = 0;
    protected int $bid = 0;

 
     

    protected function getCustNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid);  

            $inv = new AccountCustomerModel();
            $custno = $inv->GetCountByCompany($this->cid);
            //$year = Carbon::now()->format('y');
            //$month = strtoupper(Carbon::now()->format('M'));

            $this->newNum = strtoupper($com->data["prefix"])."/". str_pad($custno + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

    protected function getInternalName()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid);  

            $inv = new AccountCustomerModel();
            $custno = $inv->GetCountByCompany($this->cid);
            //$year = Carbon::now()->format('y');
            //$month = strtoupper(Carbon::now()->format('M'));

            $this->newNum = strtoupper($com->data["prefix"])."/". str_pad($custno + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

 

}