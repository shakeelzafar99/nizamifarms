<?php

namespace App\Models\CRM;

use App\Models\SCM\StockModel;
use App\Models\SCM\StockDetailModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\FIN\ArTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\FIN\ArPaymentDetailModel;
use Carbon\Carbon;
use App\Models\CRM\CustomerModel;

class OrderModel extends BaseModel
{
    use HasFactory, Notifiable, ArTransaction;
    protected $table = 't_crm_orders';
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
        'shopify_id',
        'contact_email',
        'created_at',
        'currency',
        'name',
        'order_number',
        'subtotal_price',
        'total_price',
        'total_tax',
        'total_weight',
        'updated_at',
        'customer_id',
        'created_by',
        'updated_by',
        'source'
    ];

    protected $map = [
        'id',
        'shopify_id',
        'contact_email',
        'created_at',
        'currency',
        'name',
        'order_number',
        'subtotal_price',
        'total_price',
        'total_tax',
        'total_weight',
        'updated_at',
        'customer_id',
        'created_by',
        'updated_by',
        'source'
    ];


    public function OrderDetails()
    {
        return $this->hasMany(OrderDetailModel::class, 'order_id', 'id');
    }
    public function OrderAddress()
    {
        return $this->hasMany(OrderAddressModel::class, 'order_id', 'id');
    }

    public function Customer()
    {
        return $this->hasOne(CustomerModel::class, 'id', 'customer_id');
    }


    function List($data) //All record
    {
        $this->listRequest($data);

        $this->data = OrderModel::where($this->filter)->whereLike([
            'order_no',
            'reg_no',
            'cust_name',
            'phone_no',
            'email',
            'order_date',
            'description',
            'tot_qty',
            'tot_price',
            'vat_rate',
            'tot_vat',
            'tot_price_vat',
        ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = OrderModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetDetail($id) //All record
    {
        try {
            $this->data = OrderModel::find($id)->toArray();
            $this->data["OrderDetailModel"] =  OrderModel::find($id)->OrderDetails()->get()->toArray();
            $this->data["CompanyModel"] =  OrderModel::find($id)->Company()->get()->toArray();
            $this->data["CompanyConfigModel"] =  OrderModel::find($id)->CompanyConfig()->get()->toArray();
            $this->data["BranchModel"] =  OrderModel::find($id)->Branch()->get()->toArray();
            $this->data["AccountCustomer"] =  OrderModel::find($id)->AccountCustomer()->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }


    // function GetDetail($id) //All record
    // {
    //     try {
    //         $this->data = OrderModel::find($id)->toArray();
    //         $this->data["OrderDetailModel"] =  OrderModel::find($id)->OrderDetails()->get()->toArray();
    //         return $this->setResponse();
    //     } catch (\Exception $e) {
    //         dd($e->getMessage());
    //     }
    // }


    function Store($data)
    {

        try {

            //Model Initialized 
            $model = new OrderModel;
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("shopify_id", $this->data) && $this->data['shopify_id'] > 0) {
                $model = OrderModel::where("shopify_id", $this->data['shopify_id'])->first();
                if ($model == null) {
                    $model = new OrderModel;
                }
            } else {
                $model->created_by =  $this->CurrentUserId();
            }
            $model->created_by =  $this->CurrentUserId();
            $model->contact_email = $this->data['contact_email'];
            $model->currency = $this->data['currency'];
            $model->name = $this->data['name'];
            $model->order_number = $this->data['order_number'];
            $model->subtotal_price = $this->data['subtotal_price'];
            $model->total_price = $this->data['total_price'];
            $model->total_tax = $this->data['total_tax'];
            $model->total_weight = $this->data['total_weight'];
            $model->customer_id = $this->data["customer"]['id'];
            $model->source = $this->data['source'];
            $model->shopify_id = $this->data['shopify_id'];

            DB::beginTransaction();
            if ($model->save()) {
                if (array_key_exists("line_items", $this->data) && count($this->data['line_items']) > 0) {
                    $model->OrderDetails()->delete();
                    $orderDetails = [];
                    $stockDetailsData = [];

                    foreach ($this->data['line_items'] as $key => $value) {
                        $detailModel = new OrderDetailModel();
                        $detailModel->order_id = $model->id;
                        $detailModel->product_id = $value['product_id'];
                        $detailModel->sku = $value['sku'];
                        $detailModel->quantity = $value['quantity'];
                        $detailModel->price = $value['price'];
                        $detailModel->name = $value["name"];
                        $detailModel->vendor = $value["vendor"];
                        $detailModel->shopify_line_item_id = $value["id"];
                        $detailModel->created_by =  $this->CurrentUserId();

                        $orderDetails[] = $detailModel;
                    }
                    $model->OrderDetails()->saveMany($orderDetails);
                }

                if (array_key_exists("customer", $this->data) && count($this->data['customer']) > 0) {
                    $value =  $this->data["customer"];
                    $customerModel = CustomerModel::where("shopify_cust_id", $value["id"])->first();
                    if ($customerModel == null) {
                        $customerModel = new CustomerModel();
                    }
                    $customerModel->email = $value['email'];
                    $customerModel->first_name = $value['first_name'];
                    $customerModel->last_name = $value['last_name'];
                    $customerModel->state = $value['state'];
                    $customerModel->note = $value["note"];
                    $customerModel->verified_email = $value["verified_email"];
                    $customerModel->tax_exempt = $value["tax_exempt"];
                    $customerModel->phone = $value["phone"];
                    $customerModel->shopify_cust_id = $value["id"];
                    $customerModel->created_by =  $this->CurrentUserId();
                    $customerModel->save();
                }

                if (array_key_exists("shipping_address", $this->data) && count($this->data['shipping_address']) > 0) {
                    $value =  $this->data["shipping_address"];

                    $addModel = OrderAddressModel::where("order_id", $model->id)->first();
                    // FIX: Check if $addModel is null, not $model
                    if ($addModel == null) {
                        $addModel = new OrderAddressModel();
                    }

                    $addModel->first_name = $value['first_name'];
                    $addModel->last_name = $value['last_name'];
                    $addModel->address1 = $value['address1'];
                    $addModel->address2 = $value['address2'];
                    $addModel->phone = $value["phone"];
                    $addModel->city = $value['city'];
                    $addModel->zip = $value["zip"];
                    $addModel->country = $value["country"];
                    $addModel->order_id = $model->id;
                    $addModel->created_by =  $this->CurrentUserId();

                    $addModel->save();
                }



                DB::commit();
                $this->data["id"] = $model->id;
                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (\Exception $e) {
            dd($e);
            DB::rollBack();
            $this->message = $e->getMessage();
            $this->trxnNotCompleted();
            return;
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (OrderModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
