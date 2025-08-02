<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArPaymentModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ar_payment';
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
        'cust_id',
        'order_id',
        'ar_invoice_id',
        'pymt_no',
        'pymt_date',
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
        'cust_id',
        'order_id',
        'ar_invoice_id',
        'pymt_no',
        'pymt_date',
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

    public function ArPaymentDetails()
    {
        return $this->hasMany(ArPaymentDetailModel::class, 'ar_payment_id', 'id');
    }
    public function ArPaymentSource()
    {
        return $this->hasMany(ArPaymentSourceModel::class, 'ar_payment_id', 'id');
    }
    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = ArPaymentModel::where($this->filter)->whereLike([
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
        $this->data = ArPaymentModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetDetail($id) //All record
    {
        try {
            $this->data = ArPaymentModel::find($id)->toArray();
            $this->data["ArPaymentDetailModel"] =  ArPaymentModel::find($id)->ArPaymentDetails()->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }

    function GetCountByBranch($id) //Single  
    {
        $list = ArPaymentModel::where('branch_id', '=', $id)->get();
        return $listCount = $list->count();
    }

    function Create($data)
    {
        try {
            //Model Initialized 
            $model = new ArPaymentModel;
            //Set Data Validation Error Messages
            // $this->err_msgs = [
            //     'pymt_no.required' => 'Please enter description',
            // ];
            // //Set Data Validation Rules
            // $this->rules = [
            //     'pymt_no' => 'required',
            // ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ArPaymentModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by =  $this->CurrentUserId();
            } 

            $model->company_id = $this->data['company_id']; 
            $model->branch_id = $this->data['branch_id']; 
            $model->order_id = $this->data['order_id'];
            if (isset($this->data['cust_id'])) {
                $model->cust_id = $this->data['cust_id'];
            }
            $model->ar_invoice_id = $this->data['ar_invoice_id'];
            $model->pymt_no = $this->data['pymt_no'];
            $model->pymt_date = $this->data['pymt_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {

                if (array_key_exists("ArPaymentDetailModel", $this->data) && count($this->data['ArPaymentDetailModel']) > 0) {

                    $model->ArPaymentDetails()->delete();
                    $pymtDetails = [];

                    try {
                        foreach ($this->data['ArPaymentDetailModel'] as $key => $value) {

                            $detailModel = new ArPaymentDetailModel;
                            $detailModel->company_id = $model->company_id;
                            $detailModel->branch_id = $model->branch_id;
                            if (isset($this->data['cust_id'])) {
                                $detailModel->cust_id = $model->cust_id;
                            }                            
                            $detailModel->order_id = $model->order_id;
                            $detailModel->ar_invoice_id = $model->ar_invoice_id;
                            $detailModel->ar_payment_id = $model->id;
                            $detailModel->tot_amt = $value['tot_amt'];
                            $detailModel->outstanding_amt = $value['outstanding_amt'];
                            $detailModel->paid_amt = $value['paid_amt'];
                            $detailModel->remaining_amt = $value['remaining_amt'];
                            $detailModel->created_by =  $this->CurrentUserId();

                            $pymtDetails[] = $detailModel;
                        }

                        $model->ArPaymentDetails()->saveMany($pymtDetails);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->message = $e->getMessage();
                        dd($e->getMessage());
                        $this->trxnNotCompleted();
                        return false;
                    }
                }



                if (array_key_exists("ArPaymentSourceModel", $this->data) && count($this->data['ArPaymentSourceModel']) > 0) {

                    $model->ArPaymentSource()->delete();
                    $pymtSource = [];
                    try {
                        foreach ($this->data['ArPaymentSourceModel'] as $k => $val) {

                            $sourceModel = new ArPaymentSourceModel;
                            $sourceModel->company_id = $model->company_id;
                            $sourceModel->branch_id = $model->branch_id;                            
                            if (isset($this->data['cust_id'])) {
                                $sourceModel->cust_id = $model->cust_id;
                            }
                            $sourceModel->order_id = $model->order_id;
                            $sourceModel->ar_invoice_id = $model->ar_invoice_id;
                            $sourceModel->ar_payment_id = $model->id;

                            $sourceModel->payment_method_id = $val['payment_menthod'];
                            $sourceModel->amount = $val['amount'];
                            if (array_key_exists("ref_no", $val)) {
                                $sourceModel->ref_no = $val['ref_no'];
                            }
                            $sourceModel->description = "Sales payment";
                            $sourceModel->created_by =  $this->CurrentUserId();
                            $pymtSource[] = $sourceModel;
                        }


                        $model->ArPaymentSource()->saveMany($pymtSource);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->message = $e->getMessage();
                        dd($e->getMessage());
                        $this->trxnNotCompleted();
                        return false;
                    }
                }

                $this->transId = $model->id;
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->message = $e->getMessage();
            dd($e->getMessage());
            $this->trxnNotCompleted();
            return false;
        }
    }

    function Store($data)
    {
        try {


            DB::beginTransaction();
            $this->Create($data);
            DB::commit();
            return $this->setResponse();
        } catch (\Exception $e) {
            DB::rollBack();
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (ArPaymentModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
