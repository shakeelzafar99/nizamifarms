<?php

namespace App\Models\Webhook;

use App\Models\CRM\OrderModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\FIN\ArTransaction;
use Illuminate\Support\Facades\DB;
use App\Models\FIN\ArPaymentDetailModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class ShopifyModel extends BaseModel
{
    use HasFactory, Notifiable, ArTransaction;
    protected $table = 't_webhook_shopify_orders';
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
        'customer_name',
        'email',
        'total_price',
        'currency',
        'created_at_shopify',
        'raw_payload',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'order_id',
        'customer_name',
        'email',
        'total_price',
        'currency',
        'created_at_shopify',
        'raw_payload',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    function List($data) //All record
    {
        return  $this->listRequest($data);

        // $this->data = ShopifyModel::where($this->filter)->whereLike([
        //     'order_no',
        //     'reg_no',
        //     'cust_name',
        //     'phone_no',
        //     'email',
        //     'order_date',
        //     'description',
        //     'tot_qty',
        //     'tot_price',
        //     'vat_rate',
        //     'tot_vat',
        //     'tot_price_vat',
        // ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        // return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = ShopifyModel::find($id)->toArray();
        return $this->setResponse();
    }


    function Store($data)
    {
        try {
            //Model Initialized 
            $model = new OrderModel;

            $this->data = $data;
            $this->data['shopify_id'] = $this->data["id"];
            $this->data['source'] = "shopify";

            $response = $model->Store($this->data);

            $this->trxnCompleted();
            return $this->setResponse();
        } catch (\Exception $e) {
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
        if (ShopifyModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
