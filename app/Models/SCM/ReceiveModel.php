<?php

namespace App\Models\SCM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\FIN\ApTransaction;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\DB;
use App\Models\FIN\ApPaymentDetailModel;

class ReceiveModel extends BaseModel
{
    use HasFactory, Notifiable, ApTransaction;
    protected $table = 't_scm_receive';
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
        'po_no',
        'po_date',
        'description',
        'tot_qty',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'is_active',
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
        'po_no',
        'po_date',
        'description',
        'tot_qty',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    public function ReceiveDetails()
    {
        return $this->hasMany(ReceiveDetailModel::class, 'receive_id', 'id');
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = ReceiveModel::where($this->filter)->whereLike([
            'po_no',
            'po_date',
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
        $this->data = ReceiveModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        try {
            //Model Initialized 
            $model = new ReceiveModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'inv_no.required' => 'Please enter description',
                // 'po_no.required' => 'The po number is required.',                
                // 'po_no.unique' => 'The po number has already been taken.',                
            ];
            //Set Data Validation Rules
            $this->rules = [
                'inv_no' => 'required',
                //'po_no' => 'required|unique:t_scm_purchase|max:50',

            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ReceiveModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by =  $this->CurrentUserId();
            }


            $model->company_id = $this->data['company_id'];
            $model->branch_id =  $this->data['branch_id'];
            //PO STATUS
            $po_status = $this->data['po_status'];
            //PO STATUS


            $model->purchase_id = $this->data['purchase_id'];
            $model->supplier_id = $this->data['supplier_id'];

            $model->rec_date = $this->data['inv_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            } 
            DB::beginTransaction();
            if ($model->save()) {
                if (array_key_exists("ReceiveDetailModel", $this->data) && count($this->data['ReceiveDetailModel']) > 0) {

                    $model->ReceiveDetails()->delete();
                    $receiveDetails = [];

                    foreach ($this->data['ReceiveDetailModel'] as $key => $value) {

                        $detailModel = new ReceiveDetailModel();
                        $detailModel->company_id = $model->company_id;
                        $detailModel->branch_id = $model->branch_id;
                        $detailModel->supplier_id = $model->supplier_id;
                        $detailModel->purchase_id = $model->purchase_id;
                        $detailModel->receive_id = $model->id;
                        $detailModel->product_id = $value['product_id'];
                        $detailModel->ptp_id = $value['ptp_id'];
                        $detailModel->is_part_worn = $value['is_part_worn'];
                        $detailModel->part_id = $value['part_id'];
                        $detailModel->description = $value['description'];
                        $detailModel->po_quantity = $value['po_quantity'];
                        $detailModel->rec_quantity = $value['rec_quantity'];
                        $detailModel->vat_rate = $value['vat_rate'];
                        $detailModel->unit_price = $value['unit_price'];
                        $detailModel->unit_price_vat_val = $value['unit_price_vat_val'];
                        $detailModel->unit_price_vat = $value['unit_price_vat'];
                        $detailModel->tot_price = $value['tot_price'];
                        $detailModel->tot_price_vat_val = $value['tot_price_vat_val'];
                        $detailModel->tot_price_vat = $value['tot_price_vat'];
                        $detailModel->created_by =  $this->CurrentUserId();

                        $receiveDetails[] = $detailModel;

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
                        if ($checkStock != null) {
                            $stockData["id"] = $checkStock["id"];
                        }
                        //Check Existing Stock 
                        //Set Data                        
                        $stockData["company_id"]   = $model->company_id;
                        $stockData["branch_id"]    = $model->branch_id;
                        $stockData["product_id"]  = $value['product_id'];
                        $stockData["ptp_id"]   = $value['ptp_id'];
                        $stockData["is_part_worn"]   = $value['is_part_worn'];
                        $stockData["part_id"]      = $value['part_id'];
                        $stockData["stock_value"]  = $value['rec_quantity'];
                        $stockData["purchase_price"]  = $value['unit_price'];
                        $stockData["tot_purch_val"]  = $value['tot_price'];
                        $stock->Create($stockData);
                        //Update Stock
                    }
                    $model->ReceiveDetails()->saveMany($receiveDetails);
                }

                //Update Purchase Status
                $purchase = PurchaseModel::find($model->purchase_id);
                $purchase->po_status = "C";
                $purchase->save();
                //Update Purchase Status

                $paidAmt = 0;
                $outStandingAmt =  $model->tot_price_vat;
                //Paid Amount 
                if ($po_status != "CR" && array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                    $items = $this->data['PaymentDetailModel'];
                    $paidAmt = array_sum(array_column($items, 'amount'));
                    $outStandingAmt =  $model->tot_price_vat - $paidAmt;
                }
                //Paid Amount  

                //Invoice      
                $this->header                   =  $model->getOriginal();
                $this->header["id"]             =  0;
                $this->header["receive_id"]     =  $model->id;
                $this->header["inv_no"]         =  $this->data['inv_no'];
                $this->header["inv_date"]       =  $this->data['inv_date'];
                $this->header["inv_status"]     =  $po_status;
                $this->header["paid_amt"]       =  $paidAmt;
                $this->header["outstanding_amt"]  =  $outStandingAmt;
                $this->details                  = $this->data['ReceiveDetailModel'];
                $this->apInvoice();
                //Invoice  
                if ($po_status != "CR" && array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                    //Payment  

                    $this->header                   =  $model->getOriginal();
                    $this->header["id"]             =  0;
                    $this->header["receive_id"]     =  $model->id;
                    $this->header["ap_invoice_id"]  = session('transId');
                    // $this->header["pymt_no"]        =   $this->newNum;
                    $this->header["pymt_date"]      =  $this->data['inv_date'];
                    $this->header["tot_amount"]     =  $paidAmt;

                    $apPayDetails = [];
                    $apPaydetailModel = new ApPaymentDetailModel();

                    $apPaydetailModel->company_id = $model->company_id;
                    $apPaydetailModel->branch_id = $model->branch_id;
                    $apPaydetailModel->supplier_id = $model->supplier_id;
                    $apPaydetailModel->purchase_id = $model->purchase_id;
                    $apPaydetailModel->ap_invoice_id = session('transId');
                    $apPaydetailModel->tot_amt =  $model->tot_price_vat;
                    $apPaydetailModel->outstanding_amt =  $model->tot_price_vat;
                    $apPaydetailModel->paid_amt =  $paidAmt;
                    $apPaydetailModel->remaining_amt = $outStandingAmt;
                    $apPaydetailModel->created_by =  $this->CurrentUserId();

                    $apPayDetails[]                 =  $apPaydetailModel;
                    $this->details                  =  $apPayDetails;
                    $this->paymentSource            =  $this->data['PaymentDetailModel'];
                    $this->apPayment();
                    //Payment
                }

                DB::commit();
                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            dd($e->getMessage());
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (ReceiveModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
