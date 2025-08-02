<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\CompanyConfigModel;
use App\Models\CRM\BranchModel;
use App\Models\SCM\SupplierModel;
use Illuminate\Support\Facades\DB;
use App\Traits\FIN\ApTransaction;

class ApPaymentModel extends BaseModel
{
    use HasFactory, Notifiable, ApTransaction;
    protected $table = 't_fin_ap_payment';
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
        'pymt_no',
        'pymt_date',
        'description',
        'tot_amount',
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
        'pymt_no',
        'pymt_date',
        'description',
        'tot_amount',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    public function ApPaymentDetails()
    {
        return $this->hasMany(ApPaymentDetailModel::class, 'ap_payment_id', 'id');
    }
    public function ApPaymentSource()
    {
        return $this->hasMany(ApPaymentSourceModel::class, 'ap_payment_id', 'id');
    }
    public function Supplier()
    {
        return $this->hasOne(SupplierModel::class, 'id', 'supplier_id');
    }

    public function Company()
    {
        return $this->hasOne(CompanyModel::class, 'id', 'company_id');
    }

    public function CompanyConfig()
    {
        return $this->hasOne(CompanyConfigModel::class, 'company_id', 'company_id');
    }


    public function Branch()
    {
        return $this->hasOne(BranchModel::class, 'id', 'branch_id');
    }



    function List($data) //All record

    {

        try {
            $this->listRequest($data);

            $this->data = ApPaymentModel::join('t_scm_supplier', 't_scm_supplier.id', '=', 't_fin_ap_payment.supplier_id')
                ->where($this->filter)->whereLike([
                    'pymt_no',
                    'description'
                ], $this->searchTerm)->orderBy($this->column, $this->direction)->select('t_fin_ap_payment.*', 't_scm_supplier.supplier_name')->paginate($this->pageSize)->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function Get($id) //Single  

    {
        $this->data = ApPaymentModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetCountByBranch($id) //Single  

    {
        $list = ApPaymentModel::where('branch_id', '=', $id)->get();
        return $listCount = $list->count();
    }

    function GetDetail($id) //All record

    {
        try {
            $this->data = ApPaymentModel::find($id)->toArray();
            $data = array('filter' => array('ap_payment_id' => $id));
            $find["filter"] = [];
            $find["filter"]["t_fin_ap_payment_detail.ap_payment_id"] = $id;
            $paymentDetail = new ApPaymentDetailModel();
            $paymentDetailData = $paymentDetail->List($find);
            $this->data["ApPaymentDetailModel"] = $paymentDetailData->data;
            $find["filter"] = [];
            $find["filter"]["t_fin_ap_payment_source.ap_payment_id"] = $id;
            $paymentSource = new ApPaymentSourceModel();
            $paymentSourceData = $paymentSource->List($find);
            $this->data["ApPaymentSourceModel"] = $paymentSourceData->data;
            $this->data["SupplierModel"] = ApPaymentModel::find($id)->Supplier()->get()->toArray();
            $this->data["CompanyModel"] = ApPaymentModel::find($id)->Company()->get()->toArray();
            $this->data["CompanyConfigModel"] = ApPaymentModel::find($id)->CompanyConfig()->get()->toArray();
            $this->data["BranchModel"] = ApPaymentModel::find($id)->Branch()->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }
    // function GetDetail($id) //All record
    // {
    //     try {
    //         $this->data = ApPaymentModel::find($id)->toArray();
    //         $this->data["ApPaymentDetailModel"] =  ApPaymentModel::find($id)->ApPaymentDetails()->get()->toArray();
    //         return $this->setResponse();
    //     } catch (\Exception $e) {
    //         dd($e->getMessage());
    //     }
    // }

    function Create($data)
    {
        try {
            //Model Initialized 
            $model = new ApPaymentModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'pymt_no.required' => 'Please enter description',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'pymt_no' => 'required',
            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ApPaymentModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by = $this->CurrentUserId();
            }


            //Payment Number
            $this->cid = $this->data['company_id'];
            $this->bid = $this->data['branch_id'];
            $this->getApPaymentNo();
            //Payment Number 

            $model->company_id = $this->data['company_id'];
            $model->branch_id = $this->data['branch_id'];
            $model->supplier_id = $this->data['supplier_id'];
            $model->pymt_no = $this->newNum;
            $model->pymt_date = $this->data['pymt_date'];
            $model->description = $this->data['description'];
            $model->tot_amount = $this->data['tot_amount'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {

                if (array_key_exists("ApPaymentDetailModel", $this->data) && count($this->data['ApPaymentDetailModel']) > 0) {

                    try {
                        $model->ApPaymentDetails()->delete();


                        $pymtDetails = [];
                        foreach ($this->data['ApPaymentDetailModel'] as $key => $value) {

                            $detailModel = new ApPaymentDetailModel;
                            $detailModel->company_id = $model->company_id;
                            $detailModel->branch_id = $model->branch_id;
                            $detailModel->supplier_id = $model->supplier_id;
                            $detailModel->purchase_id = $value['purchase_id'];
                            $detailModel->ap_invoice_id = $value['ap_invoice_id'];
                            $detailModel->ap_payment_id = $model->id;
                            $detailModel->tot_amt = $value['tot_amt'];
                            $detailModel->outstanding_amt = $value['outstanding_amt'];
                            $detailModel->paid_amt = $value['paid_amt'];
                            $detailModel->remaining_amt = $value['remaining_amt'];
                            $detailModel->created_by = $this->CurrentUserId();

                            $pymtDetails[] = $detailModel;
                        }

                        $model->ApPaymentDetails()->saveMany($pymtDetails);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        dd($e->getMessage());
                    }
                }


                if (array_key_exists("ApPaymentSourceModel", $this->data) && count($this->data['ApPaymentSourceModel']) > 0) {

                    $model->ApPaymentSource()->delete();
                    $pymtSource = [];
                    try {
                        foreach ($this->data['ApPaymentSourceModel'] as $k => $val) {

                            $sourceModel = new ApPaymentSourceModel;
                            $sourceModel->company_id = $model->company_id;
                            $sourceModel->branch_id = $model->branch_id;
                            $sourceModel->supplier_id = $model->supplier_id;
                            $sourceModel->ap_payment_id = $model->id;
                            $sourceModel->payment_method_id = $val['payment_menthod'];
                            $sourceModel->amount = $val['amount'];
                            if (array_key_exists("ref_no", $val)) {
                                $sourceModel->ref_no = $val['ref_no'];
                            }
                            $sourceModel->description = "General Payable";
                            $sourceModel->created_by = $this->CurrentUserId();
                            $pymtSource[] = $sourceModel;
                        }


                        $model->ApPaymentSource()->saveMany($pymtSource);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        dd($e->getMessage());
                    }
                }

                $this->transId = $model->id;
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            $this->intlSrvError();
        }
    }

    function OutStandingPayment($data)
    {
        try {
            //Model Initialized 
            $model = new ApPaymentModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'supplier_id.required' => 'Please select supplier',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'supplier_id' => 'required',
            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ApPaymentModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by = $this->CurrentUserId();
            }

            $currentUser = $this->currentUser();

            //Payment Number
            $this->cid = $currentUser->company_id;
            $this->bid = $currentUser->branch_id;
            $this->getApPaymentNo();
            //Payment Number 

            $model->company_id = $currentUser->company_id;
            $model->branch_id = $currentUser->branch_id;
            $model->supplier_id = $this->data['supplier_id'];
            $model->pymt_no = $this->newNum;
            $model->pymt_date = $this->data['pymt_date'];
            $model->description = $this->data['description'];
            $model->tot_amount = $this->data['tot_amount'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {


                if (array_key_exists("OutstandingInvFA", $this->data) && count($this->data['OutstandingInvFA']) > 0) {

                    try {

                        $pymtDetails = [];

                        foreach ($this->data['OutstandingInvFA'] as $key => $value) {
                            if ($value["checkbox"] === true) {
                                $detailModel = new ApPaymentDetailModel;
                                $detailModel->company_id = $model->company_id;
                                $detailModel->branch_id = $model->branch_id;
                                $detailModel->supplier_id = $model->supplier_id;
                                $detailModel->purchase_id = $value['purchase_id'];
                                $detailModel->ap_invoice_id = $value['id'];
                                $detailModel->ap_payment_id = $model->id;
                                $detailModel->tot_amt = $value['tot_price_vat'];
                                $detailModel->outstanding_amt = $value['outstanding_amt'];
                                $detailModel->paid_amt = $value['paid_amt'];
                                $detailModel->remaining_amt = $value['outstanding_amt'] - $value['paid_amt'];
                                $detailModel->created_by = $this->CurrentUserId();

                                $pymtDetails[] = $detailModel;
                                $inv = new ApInvoiceModel;
                                $invDate = [];
                                $invDate["id"] = $value['id'];
                                $invDate["paid_amt"] = $value['paid_amt'];
                                $invRet = $inv->UpdateOutstandingInv($invDate);
                                if (!$invRet) {
                                    DB::rollBack();
                                    $this->message = "Transaction invoice details not updated";
                                    $this->trxnNotCompleted();
                                    return;
                                }
                            }
                        }
                        if (count($pymtDetails) > 0) {
                            $model->ApPaymentDetails()->saveMany($pymtDetails);
                        } else {
                            DB::rollBack();
                            $this->message = "Transaction payment details not found";
                            $this->trxnNotCompleted();
                            return;
                        }
                    } catch (\Exception $e) {
                        DB::rollBack();
                        dd($e->getMessage());
                        $this->message = $e->getMessage();
                        $this->trxnNotCompleted();
                        return;
                    }
                } else {
                    DB::rollBack();
                    $this->message = "Transaction payment details not found";
                    $this->trxnNotCompleted();
                    return;
                }


                if (array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {

                    $pymtSource = [];
                    try {
                        foreach ($this->data['PaymentDetailModel'] as $k => $val) {

                            $sourceModel = new ApPaymentSourceModel;
                            $sourceModel->company_id = $model->company_id;
                            $sourceModel->branch_id = $model->branch_id;
                            $sourceModel->supplier_id = $model->supplier_id;
                            $sourceModel->ap_payment_id = $model->id;
                            $sourceModel->payment_method_id = $val['payment_menthod'];
                            $sourceModel->amount = $val['amount'];
                            if (array_key_exists("ref_no", $val)) {
                                $sourceModel->ref_no = $val['ref_no'];
                            }
                            $sourceModel->description = "General Payable";
                            $sourceModel->created_by = $this->CurrentUserId();
                            $pymtSource[] = $sourceModel;
                        }


                        $model->ApPaymentSource()->saveMany($pymtSource);
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->message = $e->getMessage();
                        $this->trxnNotCompleted();
                        return;
                    }
                } else {
                    DB::rollBack();
                    $this->message = "Transaction payment source not found";
                    $this->trxnNotCompleted();
                    return;
                }

                $this->transId = $model->id;
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
            $this->intlSrvError();
        }
    }


    function Store($data)
    {
        try {
            DB::beginTransaction();
            $this->OutStandingPayment($data);
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
        if (ApPaymentModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}