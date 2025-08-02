<?php

namespace App\Models\FIN\Sys;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\FIN\ArTransaction;
use Carbon\Carbon;

class SysArInvoiceModel extends BaseModel
{
    use HasFactory, Notifiable, ArTransaction;
    protected $table = 't_fin_sys_ar_invoice';
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
        'inv_no',
        'inv_date',
        'due_date',
        'description',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'inv_status',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',

    ];

    protected $map = [
        'id',
        'company_id',
        'inv_no',
        'inv_date',
        'due_date',
        'description',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'inv_status',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    function List($data) //All record
    {
        try {
            $this->listRequest($data);
            if ($this->column == "id") {
                $this->column = "t_fin_sys_ar_invoice.id";
            }
            $this->data = SysArInvoiceModel::join('t_crm_company', 't_crm_company.id', '=', 't_fin_sys_ar_invoice.company_id')
                ->where($this->filter)->whereLike(['description'], $this->searchTerm)->orderBy($this->column, $this->direction)
                ->select('t_fin_sys_ar_invoice.*', 't_crm_company.company_name')->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = SysArInvoiceModel::join('t_crm_company', 't_crm_company.id', '=', 't_fin_sys_ar_invoice.company_id')
            ->select(
                't_fin_sys_ar_invoice.*',
                't_crm_company.company_name',
                't_crm_company.email',
                't_crm_company.contact_person',
                't_crm_company.contact_no',
                't_crm_company.address_first_line',
                't_crm_company.address_second_line',
                't_crm_company.city',
                't_crm_company.postcode',
                't_crm_company.logo'
            )
            ->find($id)->toArray();
        return $this->setResponse();
    }
    function GetCountByCompany($id) //Single  
    {
        $list = SysArInvoiceModel::where('company_id', '=', $id)->get();
        return $listCount = $list->count();
    }
    function GetCountByYear() //Single  
    {
        $list = SysArInvoiceModel::whereBetween('created_at', [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()])->get();
        return  $list->count();
    }
 

    function Email($id) //Single  
    {
        $model = $this->Get($id);

        //Welcome Email
        $this->templateId = "myg-invoice";
        $this->arrData =  $model->data;
        $this->toName  = $model->data["company_name"];
        $this->toEmail = $model->data["email"];
        $this->mygInvoiceEmail();
        //Welcome Email 
        return $this->setResponse();
    }

    // function Store($data)
    // {
    //     //Model Initialized 
    //     $model = new SysArInvoiceModel;
    //     //Set Data Validation Error Messages
    //     $this->err_msgs = [
    //         'description.required' => 'Please enter description',
    //         'description.unique' => 'The description has already been taken.',
    //     ];
    //     //Set Data Validation Rules
    //     $this->rules = [
    //         'description' => 'required|unique:t_sys_ar_invoice|max:100',

    //     ];
    //     $this->data = $data;

    //     // Validate the request...
    //     if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
    //         $model = SysArInvoiceModel::find($this->data['id']);
    //         if ($model == null) {
    //             $this->dataNotFound();
    //             return $this->setResponse();
    //         }
    //         // $this->rules['name'] =  $this->rules['name'] . ',name,' . $model->id;
    //         $model->updated_by = $this->CurrentUserId();
    //         $model->is_active   =  $this->data['is_active'];
    //     } else {
    //         $model->created_by =  $this->CurrentUserId();
    //     }

    //     //Invoice Number
    //     $this->cid =  $model->company_id;
    //     $this->getSysArInvoiceNo();
    //     //Invoice Number 

    //     $model->company_id = $this->data['company_id'];
    //     $model->inv_no = $this->newNum;
    //     $model->inv_date   =  $this->data['inv_date'];
    //     $model->is_vat_inv   =  $this->data['is_vat_inv'];
    //     $model->inv_frq = $this->data['inv_frq'];
    //     $model->no_of_br = $this->data['no_of_br'];
    //     $model->per_br_price   =  $this->data['per_br_price'];
    //     $model->tot_price   =  $this->data['tot_price'];
    //     $model->vat_rate = $this->data['vat_rate'];
    //     $model->tot_vat = $this->data['tot_vat'];
    //     $model->tot_price_vat   =  $this->data['tot_price_vat'];
    //     $model->inv_status   =  $this->data['inv_status'];
    //     $model->description = $this->data['description'];


    //     if (!$this->dataValidation()) {
    //         return $this->setResponse();
    //     }

    //     // if ($model->save()) {
    //     //     $this->trxnCompleted();
    //     //     return $this->setResponse();
    //     // }

    //     if ($model->save()) {
    //         if (array_key_exists("SysArPaymentSourceModel", $this->data) && count($this->data['SysArPaymentSourceModel']) > 0) {

    //             $model->PurchaseDetails()->delete();
    //             $purchaseDetails = [];

    //             foreach ($this->data['SysArPaymentSourceModel'] as $key => $value) {

    //                 $detailModel = new SysArPaymentSourceModel;
    //                 $detailModel->company_id = $model->company_id;
    //                 $detailModel->ar_invoice_id = $model->id;
    //                 $detailModel->ar_payment_id = $value['ar_payment_id'];
    //                 $detailModel->ar_invoice_id = $value['ar_invoice_id'];
    //                 $detailModel->payment_method_id = $value['payment_method_id'];
    //                 $detailModel->amount = $value['amount'];
    //                 $detailModel->ref_no = $value['ref_no'];
    //                 $detailModel->description = $value['description'];

    //                 $purchaseDetails[] = $detailModel;
    //             }
    //             $model->PurchaseDetails()->saveMany($purchaseDetails);
    //         }

    //         $this->trxnCompleted();
    //         return $this->setResponse();
    //     }

    //     $this->intlSrvError();
    //     return $this->setResponse();
    // }

    function Remove($id) //DELETE
    {
        if (SysArInvoiceModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
