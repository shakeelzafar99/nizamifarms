<?php

namespace App\Models\FIN\Sys; 
use App\Models\Shared\BaseModel; 

class SysReportsModel extends BaseModel
{
    
   
    function Cashup($data) //All record
    {
        try {
            $this->listRequest($data);
            if ($this->column == "id") {
                $this->column = "t_fin_sys_ar_payment_source.id";
            }
            $this->data = SysArPaymentSourceModel::join('t_sys_payment_method', 't_sys_payment_method.id', '=', 't_fin_sys_ar_payment_source.payment_method_id')
                ->join('t_fin_sys_ar_payment', 't_fin_sys_ar_payment.id', '=', 't_fin_sys_ar_payment_source.sys_ar_payment_id')
                ->join('t_fin_sys_ar_invoice', 't_fin_sys_ar_invoice.id', '=', 't_fin_sys_ar_payment_source.sys_ar_invoice_id')
                ->join('t_crm_company', 't_crm_company.id', '=', 't_fin_sys_ar_payment_source.company_id')
                ->where($this->filter)->whereLike(['description'], $this->searchTerm)->orderBy($this->column, $this->direction)
                ->select('t_fin_sys_ar_payment_source.*','t_crm_company.internal_name','t_crm_company.company_name','t_fin_sys_ar_invoice.inv_no','t_fin_sys_ar_invoice.inv_date', 't_fin_sys_ar_payment.pymt_no','t_fin_sys_ar_payment.pymt_date', 't_sys_payment_method.type', 't_sys_payment_method.name as payment_method')->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }
   
}
