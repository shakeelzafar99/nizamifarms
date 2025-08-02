<?php

namespace App\Traits\FIN;

use App\Models\FIN\ArInvoiceModel;
use App\Models\FIN\ArPaymentModel;
use App\Models\FIN\Sys\SysArPaymentModel;
use App\Models\FIN\Sys\SysArInvoiceModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\BranchModel;
use App\Models\CRM\OrderModel;
use Carbon\Carbon;

trait ArTransaction
{

    protected array $header = [];
    protected array $details = [];
    protected array $paymentSource = [];
    protected array $transData = [];
    protected int $cid = 0;
    protected string $newNum = "";
    protected function arInvoice()
    {
        try {
            $arInvoice = new ArInvoiceModel();
            $this->transData = $this->header;
            $this->transData["ArInvoiceDetailModel"] = $this->details;
            $response = $arInvoice->Create($this->transData);
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function arPayment()
    {
        try {

            $arPayment = new ArPaymentModel();
            $this->transData = $this->header;
            $this->transData["ArPaymentDetailModel"] = $this->details;
            $this->transData["ArPaymentSourceModel"] = $this->paymentSource;
            $response = $arPayment->Create($this->transData);
        } catch (\Exception $e) {
            return 0;
        }
    }


    protected function getSysArPaymentNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid);
            $payment = new SysArPaymentModel();
            $pymt = $payment->GetCountByCompany($this->cid); 
            $year = Carbon::now()->format('y');
            $month = strtoupper(Carbon::now()->format('M'));            
            $this->newNum = "PYMT/" . str_pad($month, 2, '0', STR_PAD_LEFT)  . $year . "/" . str_pad($pymt + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

    protected function getSysArInvoiceNo()
    {
        try {
            // $company = new CompanyModel();
            // $com = $company->Get($this->cid);
            $inv = new SysArInvoiceModel();
            $invoice = $inv->GetCountByYear();
            $year = Carbon::now()->format('y');
            $month = strtoupper(Carbon::now()->format('M'));
            $this->newNum = "INV/" . $year . "/" . str_pad($invoice + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

    protected function getOrderNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid); 

            $ord = new OrderModel();
            $order = $ord->GetCountByBranch($this->bid); 

            $this->newNum = "ORD/" . strtoupper($com->data["prefix"]) . "/" .  str_pad($order + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }


    protected function getArPaymentNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid); 

            $pymt = new ArPaymentModel();
            $payment = $pymt->GetCountByBranch($this->bid); 

            $this->newNum = "PYMT/" . strtoupper($com->data["prefix"]) . "/" . str_pad($payment + 1, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }

    protected function getArInvoiceNo()
    {
        try {
            $company = new CompanyModel();
            $com = $company->Get($this->cid); 
            $inv = new ArInvoiceModel();
            $invoice = $inv->GetCountByBranch($this->bid); 
            $this->newNum = "INV/" . strtoupper($com->data["prefix"]) . "/" . str_pad($invoice + 1, 6, '0', STR_PAD_LEFT);
            
        } catch (\Exception $e) {

            dd($e->getMessage());
        }
    }
}
