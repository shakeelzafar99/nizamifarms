<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class ArPaymentDetailModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ar_payment_detail';
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
        'cust_id',
        'order_id',
        'ar_invoice_id',
        'ar_payment_id',
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
        'cust_id',
        'order_id',
        'ar_invoice_id',
        'ar_payment_id',
        'tot_amt',
        'outstanding_amt',
        'paid_amt',
        'remaining_amt',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function Get($id) //Single  
    {
        $this->data = ArPaymentDetailModel::where('ar_invoice_id', $id)
            ->get()
            ->toArray();
        return $this->setResponse();
    }
}
