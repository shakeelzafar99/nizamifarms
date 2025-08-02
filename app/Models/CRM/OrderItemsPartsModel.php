<?php
namespace App\Models\CRM; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class OrderItemsPartsModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_crm_order_item_parts';
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
        'order_item_id',
        'vat_rate',
        'purchase_price',
        'purchase_price_vat',
        'tot_purchase_price',
        'tot_purchase_price_vat',
        'tot_purchase_vat',
        'sale_price',
        'sale_price_vat',
        'tot_sale_price',
        'tot_sale_price_vat',
        'tot_sale_vat',
        'quantity',
        'purchase_item_id',
        'part_id'

        ];

    protected $map = [
        'id',
        'company_id',
        'branch_id',
        'order_id',
        'order_item_id',
        'vat_rate',
        'purchase_price',
        'purchase_price_vat',
        'tot_purchase_price',
        'tot_purchase_price_vat',
        'tot_purchase_vat',
        'sale_price',
        'sale_price_vat',
        'tot_sale_price',
        'tot_sale_price_vat',
        'tot_sale_vat',
        'quantity',
        'purchase_item_id',
        'part_id'

    ];

   
    function List($data) //All record
    {  
        $this->listRequest($data);         
        $this->data = OrderItemPartsModel::where($this->filter)->whereLike(['vat_rate',
        'purchase_price',
        'purchase_price_vat',
        'tot_purchase_price',
        'tot_purchase_price_vat',
        'tot_purchase_vat',
        'sale_price',
        'sale_price_vat',
        'tot_sale_price',
        'tot_sale_price_vat',
        'tot_sale_vat',
        'quantity',], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    } 

    function Get($id) //Single  
    { 
        $this->data = OrderItemPartsModel::find($id)->toArray(); 
        return $this->setResponse();
    }


    function Store($data)
    { 
        //Model Initialized 
        $model = new OrderItemPartsModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                // 'size_desc.required' => 'Please enter size',
                // 'size_desc.unique' => 'The size has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            // 'size_desc' => 'required|unique:t_crm_order_item_parts|max:50',
            // 'purchase_price' => 'required'
        ];
        $this->data = $data;
       
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = OrderItemPartsModel::find($this->data['id']); 
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
            //$this->rules['vat_rate'] =  $this->rules['vat_rate'] . ',vat_rate,' . $model->id;
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  


        $model->company_id = $this->data['company_id'];
        $model->branch_id = $this->data['branch_id'];
        $model->order_id = $this->data['order_id'];
        $model->order_item_id = $this->data['order_item_id'];
        $model->vat_rate = $this->data['vat_rate'];
        $model->purchase_price = $this->data['purchase_price'];
        $model->purchase_price_vat = $this->data['purchase_price_vat'];
        $model->tot_purchase_price = $this->data['tot_purchase_price'];
        $model->tot_purchase_price_vat = $this->data['tot_purchase_price_vat'];
        $model->tot_purchase_vat = $this->data['tot_purchase_vat'];
        $model->sale_price = $this->data['sale_price'];
        $model->sale_price_vat = $this->data['sale_price_vat'];
        $model->tot_sale_price = $this->data['tot_sale_price'];
        $model->tot_sale_price_vat = $this->data['tot_sale_price_vat'];
        $model->tot_sale_vat = $this->data['tot_sale_vat'];
        $model->quantity = $this->data['quantity'];
        $model->part_ids = $this->data['part_id'];
        $model->purchase_item_id = $this->data['purchase_item_id'];

        if(!$this->dataValidation()){
            return $this->setResponse();
        } 

        if($model->save()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    } 

    function Remove($id) //DELETE
    {  
        if(OrderItemPartsModel::find($id)->delete()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse(); 
    }   
}
