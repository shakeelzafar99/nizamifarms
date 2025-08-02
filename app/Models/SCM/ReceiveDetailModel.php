<?php
namespace App\Models\SCM; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class ReceiveDetailModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_scm_receive_detail';
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
        'receive_id',
        'ptp_id',
        'part_id',
        'description',
        'po_quantity',
        'quantity',
        'vat_rate',
        'unit_price',
        'unit_price_vat_val',
        'unit_price_vat',
        'tot_price',
        'tot_price_vat_val',
        'tot_price_vat',
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
        'receive_id',
        'ptp_id',
        'part_id',
        'description',
        'po_quantity',
        'quantity',
        'vat_rate',
        'unit_price',
        'unit_price_vat_val',
        'unit_price_vat',
        'tot_price',
        'tot_price_vat_val',
        'tot_price_vat',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function Get($id) //Single  
    {
        $this->data = PurchaseDetailModel::where('purchase_id', $id)
               ->get()
               ->toArray();
            return $this->setResponse();
    }

 
}
