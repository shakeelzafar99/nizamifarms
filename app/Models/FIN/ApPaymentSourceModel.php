<?php
namespace App\Models\FIN; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class ApPaymentSourceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ap_payment_source';
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
        'ap_payment_id',
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
        'supplier_id',  
        'ap_payment_id',
        'payment_method_id',
        'amount',
        'ref_no',
        'description', 
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data =   ApPaymentSourceModel::join('t_sys_payment_method', 't_sys_payment_method.id', '=', 't_fin_ap_payment_source.payment_method_id')
            ->where($this->filter)->whereLike([
                'type',
                'name'
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->select('t_fin_ap_payment_source.*', 't_sys_payment_method.type', 't_sys_payment_method.name as payment_method')->get()->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = ApPaymentSourceModel::where('ap_payment_id', $id)
               ->get()
               ->toArray();
            return $this->setResponse();
    }

 
}
