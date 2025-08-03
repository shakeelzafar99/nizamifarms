<?php
namespace App\Models\CRM; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class OrderAddressModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_crm_order_address';
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
        'order_id',
        'name',
        'address',
        'city',
        'zip',
        'country',
        'phone',    
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'order_id',
        'product_id',
        'sku',
        'quantity',
        'price',
        'name',
        'vendor',    
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

 
}
