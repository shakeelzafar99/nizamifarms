<?php
namespace App\Models\FIN; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class ArPaymentSourceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ar_payment_source';
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
        'order_id',  
        'ar_invoice_id',
        'ar_payment_id',
        'payment_method_id',
        'amount',
        'ref_no',
        'description', 
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'branch_id',
        'order_id',  
        'ar_invoice_id',
        'ar_payment_id',
        'payment_method_id',
        'amount',
        'ref_no',
        'description', 
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function Get($id) //Single  
    {
        $this->data = ArPaymentSourceModel::where('ar_payment_id', $id)
               ->get()
               ->toArray();
            return $this->setResponse();
    }

 
}
