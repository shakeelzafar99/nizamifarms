<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class ApPaymentDetailModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ap_payment_detail';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'supplier_id',
        'purchase_id',
        'ap_invoice_id',
        'ap_payment_id',
        'tot_amt',
        'outstanding_amt',
        'paid_amt',
        'remaining_amt',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'branch_id',
        'supplier_id',
        'purchase_id',
        'ap_invoice_id',
        'ap_payment_id',
        'tot_amt',
        'outstanding_amt',
        'paid_amt',
        'remaining_amt',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data =   ApPaymentDetailModel::join('t_fin_ap_invoice', 't_fin_ap_invoice.id', '=', 't_fin_ap_payment_detail.ap_invoice_id')
            ->join('t_scm_purchase', 't_scm_purchase.id', '=', 't_fin_ap_invoice.purchase_id')
            ->where($this->filter)->whereLike([
                'inv_no',
                'inv_date',
                'po_no',
                'po_date'
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->select('t_fin_ap_payment_detail.*', 't_fin_ap_invoice.inv_no', 't_fin_ap_invoice.inv_date', 't_scm_purchase.po_no', 't_scm_purchase.po_date')->get()->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = ApPaymentDetailModel::where('ap_invoice_id', $id)
            ->get()
            ->toArray();
        return $this->setResponse();
    }
}
