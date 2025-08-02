<?php

namespace App\Models\FIN\Sys;

use App\Models\CRM\CompanyModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\FIN\ArTransaction;
use Illuminate\Support\Facades\DB;

class SysArPaymentModel extends BaseModel
{
    use HasFactory, Notifiable, ArTransaction;
    protected $table = 't_fin_sys_ar_payment';
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
        'sys_ar_invoice_id',
        'pymt_no',
        'pymt_date',
        'description',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',



    ];

    protected $map = [
        'id',
        'company_id',
        'sys_ar_invoice_id',
        'pymt_no',
        'pymt_date',
        'description',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];


    public function SysArPaymentSource()
    {
        return $this->hasMany(SysArPaymentSourceModel::class, 'sys_ar_payment_id', 'id');
    }

    function List($data) //All record
    {
        // $this->listRequest($data);         
        // $this->data = SysArPaymentModel::where($this->filter)->whereLike([
        //     'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        // return $this->setResponse();

        try {
            $this->listRequest($data);
            if ($this->column == "id") {
                $this->column = "t_fin_sys_ar_payment.id";
            }

            if ($this->isDateRange) {
                $this->data = SysArPaymentModel::join('t_crm_company', 't_crm_company.id', '=', 't_fin_sys_ar_payment.company_id')
                    ->where($this->filter)->whereLike(['description'], $this->searchTerm)->whereBetween('t_fin_sys_ar_payment.pymt_date', [$this->start, $this->end])->orderBy($this->column, $this->direction)
                    ->select('t_fin_sys_ar_payment.*', 't_crm_company.company_name')->paginate($this->pageSize)->toArray();
            } else {
                $this->data = SysArPaymentModel::join('t_crm_company', 't_crm_company.id', '=', 't_fin_sys_ar_payment.company_id')
                    ->where($this->filter)->whereLike(['description'], $this->searchTerm)->orderBy($this->column, $this->direction)
                    ->select('t_fin_sys_ar_payment.*', 't_crm_company.company_name')->paginate($this->pageSize)->toArray();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = SysArPaymentModel::find($id)->toArray();
        $this->data["CompanyInvoiceModel"] = SysArInvoiceModel::find($this->data["sys_ar_invoice_id"])->toArray();
        $this->data["CompanyModel"] = CompanyModel::find($this->data["company_id"])->toArray();
        $this->data["PaymentSourceModel"] = SysArPaymentSourceModel::join('t_sys_payment_method', 't_sys_payment_method.id', '=', 't_fin_sys_ar_payment_source.payment_method_id')
            ->where('sys_ar_payment_id', '=', $id)->select('t_fin_sys_ar_payment_source.*', 't_sys_payment_method.type', 't_sys_payment_method.name')->get()->toArray();
        return $this->setResponse();
    }

    function GetCountByCompany($id) //Single  
    {
        $list = SysArPaymentModel::where('company_id', '=', $id)->get();
        return $listCount = $list->count();
    }

    function Store($data)
    {

        try {
            //Model Initialized 
            $model = new SysArPaymentModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'pymt_date.required' => 'Please enter payment date',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'pymt_date' => 'required',

            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = SysArPaymentModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                // $this->rules['name'] =  $this->rules['name'] . ',name,' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $model->created_by =  $this->CurrentUserId();
            }
            $model->company_id = $this->data['company_id'];
            $model->sys_ar_invoice_id = $this->data['sys_ar_invoice_id'];
            //Payment Number
            $this->cid =  $model->company_id;
            $this->getSysArPaymentNo();
            //Payment Number 
            $model->pymt_no   =   $this->newNum;
            $model->pymt_date   =  $this->data['pymt_date'];
            $model->is_vat_inv = $this->data['is_vat_inv'];
            $model->inv_frq   =  $this->data['inv_frq'];
            $model->no_of_br = $this->data['no_of_br'];
            $model->per_br_price   =  $this->data['per_br_price'];
            $model->tot_price   =  $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat   =  $this->data['tot_price_vat'];
            $model->description = $this->data['description'];
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        DB::beginTransaction();
        if ($model->save()) {

            $this->data["id"] = $model->id;

            if (array_key_exists("PaymentDetailModel", $this->data) && count($this->data['PaymentDetailModel']) > 0) {
                try {
                    $model->SysArPaymentSource()->delete();
                    $pymtSource = [];

                    foreach ($this->data['PaymentDetailModel'] as $k => $val) {

                        $sourceModel = new SysArPaymentSourceModel;
                        $sourceModel->sys_ar_payment_id = $model->id;
                        $sourceModel->company_id = $model->company_id;
                        $sourceModel->sys_ar_invoice_id = $model->sys_ar_invoice_id;
                        $sourceModel->payment_method_id = $val['payment_menthod'];
                        $sourceModel->amount = $val['amount'];
                        if (array_key_exists("ref_no", $val)) {
                            $sourceModel->ref_no = $val['ref_no'];
                        }
                        $sourceModel->description = "General Receivable";
                        $sourceModel->created_by =  $this->CurrentUserId();
                        $pymtSource[] = $sourceModel;
                    }

                    $model->SysArPaymentSource()->saveMany($pymtSource);

                    //Update Invoice 
                    $inv = SysArInvoiceModel::find($model->sys_ar_invoice_id);
                    $inv->inv_status = "Completed";
                    $inv->updated_by = $this->CurrentUserId();
                    $inv->save();
                    //Update Invoice 

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->errors[0] = $e->getMessage();
                    $this->trxnCompleted();
                    return $this->setResponse();
                }
            }
            //End Create new user for company
            DB::commit();
            $this->trxnCompleted();
            return $this->setResponse();
        }
        DB::rollBack();
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (SysArPaymentModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
