<?php
namespace App\Models\CRM; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class OrderDetailModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_crm_order_detail';
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
        'stock_detail_id',
        'ptp_id',
        'part_id',
        'service_id',
        'description',
        'purchase_price',
        'quantity',
        'vat_rate',
        'unit_price',
        'unit_price_vat_val',
        'unit_price_vat',
        'tot_price',
        'tot_price_vat_val',
        'tot_price_vat',
        'is_validity_req',
        'is_vat_cal',
        'validity_period',
        'valid_till',        
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
        'stock_detail_id',
        'ptp_id',
        'part_id',
        'service_id',
        'description',
        'purchase_price',
        'quantity',
        'vat_rate',
        'unit_price',
        'unit_price_vat_val',
        'unit_price_vat',
        'tot_price',
        'tot_price_vat_val',
        'tot_price_vat',
        'is_validity_req',
        'is_vat_cal',
        'validity_period',
        'valid_till',        
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

 
}
