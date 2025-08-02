<?php
namespace App\Models\FIN\Sys; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class SysArPaymentSourceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_sys_ar_payment_source';
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
        'sys_ar_payment_id',
        'sys_ar_invoice_id',
        'payment_method_id',
        'amount',
        'ref_no',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $map = [
        'id',
        'company_id',
        'sys_ar_payment_id',
        'sys_ar_invoice_id',
        'payment_method_id',
        'amount',
        'ref_no',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    function Get($id) //Single  
    {
        $this->data = SysArPaymentSourceModel::where('sys_ar_payment_id', $id)
               ->get()
               ->toArray();
            return $this->setResponse();
    }

 
}
