<?php

namespace App\Models\Webhook;

use App\Models\SCM\StockModel;
use App\Models\SCM\StockDetailModel;
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
            $model = new ShopifyModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'order_id.required' => 'No order found',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'order_id' => 'required'
            ];

            //$hmacHeader = $data->header('X-Shopify-Hmac-Sha256');
            $this->data = $data;
            dd($this->data);
            $model->created_by =  $this->CurrentUserId();

            //Order STATUS
            $ord_status = $this->data['ord_status'];
            //Order STATUS
            //Order Number
            $this->cid =   $model->company_id;
            $this->bid =   $model->branch_id;
            $this->getOrderNo();
            $model->order_no = $this->newNum;
            //Order Number          
            $model->reg_no = $this->data['reg_no'];
            $cust_id = 0;

            if (isset($this->data["livesearchCustomer"])) {
                $model->is_walk_in = "N";
                $model->cust_id = $this->data["livesearchCustomer"]['id'];
                $cust_id = $this->data["livesearchCustomer"]['id'];
                $model->cust_name = $this->data["livesearchCustomer"]['company_name'];
                $model->contact_no = $this->data["livesearchCustomer"]['contact_no'];
                $model->email = $this->data["livesearchCustomer"]['email'];
            } else {
                $model->is_walk_in = "Y";
                $model->cust_name = $this->data['cust_name'];
                $model->contact_no = $this->data['contact_no'];
                $model->email = $this->data['email'];

                $walkInCustData = $walkInCust->GetByRegNo($model->reg_no,  $model->company_id); //WalkInCustomerModel::where("reg_no", "=", $model->reg_no)->where("company_id", "=", $model->company_id)->get();

                if (isset($walkInCustData->data[0])) {
                    $walkInCust = WalkInCustomerModel::find($walkInCustData->data[0]["id"]);
                    $walkInCust->updated_by =  $this->CurrentUserId();
                } else {
                    $walkInCust->created_by =   $this->CurrentUserId();
                }
                $walkInCust->company_id =  $model->company_id;
                $walkInCust->reg_no =  $model->reg_no;
                $walkInCust->full_name =  $model->cust_name;
                $walkInCust->contact_no = $model->contact_no;
                $walkInCust->email = $model->email;
                $walkInCust->save();
            }

            $model->ord_status = $this->data['ord_status'];
            $model->order_date = $this->data['order_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];
            $model->tot_cost = 0; //$this->data['tot_purchase_price'];
            // dd($this->data);
            if (!$this->dataValidation()) {
                return $this->setResponse();
            }
            DB::beginTransaction();
            if ($model->save()) {
                if (array_key_exists("OrderDetailModel", $this->data) && count($this->data['OrderDetailModel']) > 0) {
                    $model->OrderDetails()->delete();
                    $orderDetails = [];
                    $stockDetailsData = [];

                    foreach ($this->data['OrderDetailModel'] as $key => $value) {

                        if ($value['service_id'] > 0) {

                            $detailModel = new OrderDetailModel();
                            $detailModel->company_id = $model->company_id;
                            $detailModel->branch_id = $model->branch_id;
                            $detailModel->order_id = $model->id;
                            $detailModel->cust_id = $cust_id;
                            $detailModel->ptp_id = $value['ptp_id'];
                            $detailModel->part_id = $value['part_id'];
                            $detailModel->service_id = $value['service_id'];
                            $detailModel->description = $value['description'];
                            $detailModel->purchase_price = $value["cost_value"];
                            $detailModel->quantity = $value["quantity"];
                            $detailModel->vat_rate = $value['vat_rate'];
                            $detailModel->unit_price = $value['unit_price'];
                            $detailModel->unit_price_vat_val = $value['unit_price_vat_val'];
                            $detailModel->unit_price_vat = $value['unit_price_vat'];
                            $detailModel->tot_price = $value['tot_price'];
                            $detailModel->tot_price_vat_val = $value['tot_price_vat_val'];
                            $detailModel->tot_price_vat = $value['tot_price_vat'];

                            $detailModel->is_validity_req = $value['is_validity_req'];
                            $detailModel->is_vat_cal = $value['is_vat_cal'];
                            $detailModel->validity_period = $value['validity_period'];
                            $detailModel->valid_till = Carbon::parse($model->order_date)->addDays($value['validity_period']);

                            $detailModel->created_by =  $this->CurrentUserId();

                            $orderDetails[] = $detailModel;
                        } else {

                            //Stock Details
                            $stockDetailModel = new StockDetailModel();
                            $find["filter"] = [];
                            $find["filter"]["company_id"]   = $model->company_id;
                            $find["filter"]["branch_id"]    = $model->branch_id;
                            if ($value['ptp_id'] > 0) {
                                $find["filter"]["ptp_id"] = $value['ptp_id'];
                                $find["filter"]["is_part_worn"] = $value['is_part_worn'];
                            } else {
                                $find["filter"]["part_id"] = $value['part_id'];
                            }
                            $stockDetailList = $stockDetailModel->CheckStockList($find);
                            if ($stockDetailList == null) {
                                $this->dataNotFound();
                                return $this->setResponse();
                            }


                            $orderRemQty = $value['quantity'];
                            $orderQty = 0;
                            $isBreakStockLoop = false;



                            foreach ($stockDetailList as $keySkt => $stkValue) {

                                if ($stkValue["stock_value"] >= $orderRemQty) {
                                    $orderQty =  $orderRemQty;
                                    $isBreakStockLoop = true;
                                } else {
                                    $orderQty = $stkValue["stock_value"];
                                    $orderRemQty = $orderRemQty - $stkValue["stock_value"];
                                }

                                $detailModel = new OrderDetailModel();
                                $detailModel->company_id = $model->company_id;
                                $detailModel->branch_id = $model->branch_id;
                                $detailModel->order_id = $model->id;
                                $detailModel->cust_id = $cust_id;
                                $detailModel->stock_detail_id = $stkValue['id'];
                                $detailModel->ptp_id = $value['ptp_id'];
                                $detailModel->is_part_worn = $value['is_part_worn'];
                                $detailModel->part_id = $value['part_id'];
                                $detailModel->service_id = $value['service_id'];
                                $detailModel->description = $value['description'];
                                $detailModel->purchase_price = $stkValue["purchase_price"];
                                $detailModel->quantity = $orderQty;
                                $detailModel->vat_rate = $value['vat_rate'];
                                $detailModel->unit_price = $value['unit_price'];
                                $detailModel->unit_price_vat_val = $value['unit_price_vat_val'];
                                $detailModel->unit_price_vat = $value['unit_price_vat'];

                                $detailModel->tot_price = $value['unit_price'] * $orderQty;
                                $detailModel->tot_price_vat_val = $detailModel->tot_price * $value['vat_rate'];
                                $detailModel->tot_price_vat = $detailModel->tot_price + $detailModel->tot_price_vat_val;

                                $detailModel->created_by =  $this->CurrentUserId();

                                $orderDetails[] = $detailModel;

                                $stockDetailsData["id"] = $stkValue["id"];
                                $stockDetailsData["stock_value"]  = ($stkValue["stock_value"] + ($orderQty * -1));

                                $stockDetailsData["stock_value"]  = ($stkValue["stock_value"] + ($orderQty * -1));
                                $stockDetailsData["tot_sold"]  = ($stkValue["tot_sold"] + $orderQty);
                                $stockDetailsData["tot_sold_val"]  = ($stkValue["tot_sold_val"] + $detailModel->tot_price);

                                $stockDetailModel->Create($stockDetailsData);

                                if ($isBreakStockLoop) {
                                    break;
                                }
                            }


                            $stockData = [];
                            $stockData["id"] = 0;
                            //Update Stock
                            $stock = new StockModel();
                            //Check Existing Stock
                            $find["filter"] = [];
                            $find["filter"]["company_id"]   = $model->company_id;
                            $find["filter"]["branch_id"]    = $model->branch_id;

                            if ($value['ptp_id'] > 0) {
                                $find["filter"]["ptp_id"] = $value['ptp_id'];
                                $find["filter"]["is_part_worn"] = $value['is_part_worn'];
                            } else {
                                $find["filter"]["part_id"] = $value['part_id'];
                            }
                            $checkStock = $stock->CheckStock($find);
                            if ($checkStock == null) {
                                $this->dataNotFound();
                                return $this->setResponse();
                            }
                            $stockData["id"] = $checkStock["id"];
                            $stockData["action"] = "order";
                            //Check Existing Stock 
                            //Set Data                         
                            $stockData["stock_value"]  = $checkStock["stock_value"] + ($value['quantity'] * -1);

                            $stockData["tot_sold"]  = ($checkStock["tot_sold"] + $value['quantity']);
                            $stockData["tot_sold_val"]  = ($stkValue["tot_sold_val"] + $value['tot_price']);

                            $stock->Create($stockData);
                            //Update Stock

                        }
                    }
                    $model->OrderDetails()->saveMany($orderDetails);
                }


                $paidAmt = 0;
                $outStandingAmt =  $model->tot_price_vat;
                //Paid Amount 
                if ($ord_status != "CR" && array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                    $items = $this->data['PaymentDetailModel'];
                    $paidAmt = array_sum(array_column($items, 'amount'));
                    $outStandingAmt =  $model->tot_price_vat - $paidAmt;
                }
                //Paid Amount  

                //Invoice   
                //Payment Number
                $this->cid =   $model->company_id;
                $this->bid =   $model->branch_id;
                $this->getArInvoiceNo();
                //Payment Number 

                $this->header                       =  $model->getOriginal();
                $this->header["id"]                 =  0;
                $this->header["order_id"]           =  $model->id;
                $this->header["cust_id"]            =  $cust_id;
                $this->header["company_id"]         =  $model->company_id;
                $this->header["branch_id"]          =  $model->branch_id;
                $this->header["inv_no"]             =  $this->newNum;
                $this->header["inv_date"]           =  $this->data['order_date'];
                $this->header["inv_status"]         =  $ord_status;
                $this->header["paid_amt"]           =  $paidAmt;
                $this->header["outstanding_amt"]    =  $outStandingAmt;
                $this->details                      = $orderDetails; //$this->data['OrderDetailModel'];

                $this->arInvoice();
                //Invoice  

                if ($ord_status != "CR" && array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                    //Payment  
                    //Payment Number
                    $this->cid =   $model->company_id;
                    $this->bid =   $model->branch_id;
                    $this->getArPaymentNo();
                    //Payment Number 
                    $this->header                   =  $model->getOriginal();
                    $this->header["id"]             =  0;
                    $this->header["order_id"]       =  $model->id;
                    $this->header["company_id"]         =  $model->company_id;
                    $this->header["branch_id"]          =  $model->branch_id;
                    $this->header["ar_invoice_id"]  = session('transId');
                    $this->header["pymt_no"]        =   $this->newNum;
                    $this->header["pymt_date"]      =  $this->data['order_date'];
                    $this->header["tot_amount"]     =  $paidAmt;

                    $arPayDetails = [];
                    $arPaydetailModel = new ArPaymentDetailModel();

                    $arPaydetailModel->company_id = $model->company_id;
                    $arPaydetailModel->branch_id = $model->branch_id;
                    $arPaydetailModel->cust_id = $cust_id;
                    $arPaydetailModel->order_id = $model->id;
                    $arPaydetailModel->ar_invoice_id = session('transId');
                    $arPaydetailModel->tot_amt =  $model->tot_price_vat;
                    $arPaydetailModel->outstanding_amt =  $model->tot_price_vat;
                    $arPaydetailModel->paid_amt =  $paidAmt;
                    $arPaydetailModel->remaining_amt = $outStandingAmt;
                    $arPaydetailModel->created_by =  $this->CurrentUserId();

                    $arPayDetails[]                 =  $arPaydetailModel;
                    $this->details                  =  $arPayDetails;
                    $this->paymentSource            =  $this->data['PaymentDetailModel'];
                    $this->arPayment();
                    //Payment
                }



                if ($ord_status != "CR" && array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                    //Payment  
                    //Payment Number 
                    $this->getArPaymentNo();
                    //Payment Number 
                    $this->header                   =  $model->getOriginal();
                    $this->header["id"]             =  0;
                    $this->header["order_id"]       =  $model->id;
                    $this->header["ar_invoice_id"]  = session('transId');
                    $this->header["pymt_no"]        =   $this->newNum;
                    $this->header["pymt_date"]      =  $this->data['order_date'];
                    $this->header["tot_amount"]     =  $paidAmt;

                    $arPayDetails = [];
                    $arPaydetailModel = new ArPaymentDetailModel();

                    $arPaydetailModel->company_id   = $model->company_id;
                    $arPaydetailModel->branch_id    = $model->branch_id;
                    $arPaydetailModel->order_id     = $model->order_id;
                    $arPaydetailModel->ar_invoice_id = session('transId');
                    $arPaydetailModel->tot_amt      =  $model->tot_price_vat;
                    $arPaydetailModel->outstanding_amt =  $model->tot_price_vat;
                    $arPaydetailModel->paid_amt     =  $paidAmt;
                    $arPaydetailModel->remaining_amt = $outStandingAmt;
                    $arPaydetailModel->created_by   =  $this->CurrentUserId();

                    $arPayDetails[]                 =  $arPaydetailModel;
                    $this->details                  =  $arPayDetails;
                    $this->paymentSource            =  $this->data['PaymentDetailModel'];
                    $this->arPayment();
                    //Payment
                }

                // //Payment
                // $this->header                   =  $model->getOriginal();
                // $this->header["id"]             =  0;
                // $this->header["order_id"]       =  $model->id;
                // $this->header["ar_invoice_id"]  =  session('transId');
                // $this->header["pymt_date"]      =  $this->data['order_date'];
                // $this->details                  =  $this->data['OrderDetailModel'];
                // $this->paymentSource            =  $this->data['PaymentDetailModel'];
                // $this->arPayment();
                // //Payment

                DB::commit();
                $this->data["id"] = $model->id;
                $this->trxnCompleted();
                return $this->setResponse();
            }
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
