<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\CompanyConfigModel;
use App\Models\SCM\PurchaseModel;
use App\Models\CRM\BranchModel;
use App\Models\SCM\SupplierModel;


use Illuminate\Support\Facades\DB;

class ApInvoiceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ap_invoice';
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
        'receive_id',
        'inv_no',
        'inv_date',
        'description',
        'tot_qty',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'paid_amt',
        'outstanding_amt',
        'is_active',
        'inv_status',
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
        'receive_id',
        'po_no',
        'po_date',
        'description',
        'tot_qty',
        'tot_price',
        'vat_rate',
        'tot_vat',
        'tot_price_vat',
        'paid_amt',
        'outstanding_amt',
        'is_active',
        'inv_status',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    public function ApInvoiceDetails()
    {
        return $this->hasMany(ApInvoiceDetailModel::class, 'ap_invoice_id', 'id');
    }

    public function Purchase()
    {
        return $this->hasMany(PurchaseModel::class, 'id', 'purchase_id');
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
            $this->data = ApInvoiceModel::join('t_scm_supplier', 't_scm_supplier.id', '=', 't_fin_ap_invoice.supplier_id')
                ->join('t_scm_purchase', 't_scm_purchase.id', '=', 't_fin_ap_invoice.purchase_id')
                ->where($this->filter) 
                ->where($this->cFilter) 
                ->whereLike([
                    'inv_no',
                    'inv_date',
                    'po_no',
                    'po_date'
                ], $this->searchTerm)->orderBy($this->column, $this->direction)->select('t_fin_ap_invoice.*', 't_scm_supplier.supplier_name', 't_scm_purchase.po_no', 't_scm_purchase.po_date')->paginate($this->pageSize)->toArray();

            return $this->setResponse();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function GetOutstanding($company_id, $branch_id, $supplier_id) //All record
    {
        try {
            $whereData = [
                ['t_fin_ap_invoice.company_id', $company_id],
                ['t_fin_ap_invoice.branch_id', $branch_id],
                ['t_fin_ap_invoice.supplier_id', $supplier_id],
                ['t_fin_ap_invoice.outstanding_amt', '>', 0]
            ];
            // ['t_fin_ap_invoice.inv_status', "CR"],
            $this->data = ApInvoiceModel::join('t_scm_supplier', 't_scm_supplier.id', '=', 't_fin_ap_invoice.supplier_id')
                ->join('t_scm_purchase', 't_scm_purchase.id', '=', 't_fin_ap_invoice.purchase_id')
                ->whereIn('t_fin_ap_invoice.inv_status', array("CR", "P"))
                ->where($whereData)->select('t_fin_ap_invoice.*', 't_scm_supplier.supplier_name', 't_scm_purchase.po_no', 't_scm_purchase.po_date')->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }

    function Get($id) //Single  
    {
        $this->data = ApInvoiceModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetDetail($id) //All record
    {
        try {
            $this->data = ApInvoiceModel::find($id)->toArray();
            $this->data["ApInvoiceDetailModel"] =  ApInvoiceModel::find($id)->ApInvoiceDetails()->get()->toArray();
            $this->data["PurchaseModel"] =  ApInvoiceModel::find($id)->Purchase()->get()->toArray();
            $this->data["SupplierModel"] =  ApInvoiceModel::find($id)->Supplier()->get()->toArray();
            $this->data["CompanyModel"] =  ApInvoiceModel::find($id)->Company()->get()->toArray();
            $this->data["CompanyConfigModel"] =  ApInvoiceModel::find($id)->CompanyConfig()->get()->toArray();
            $this->data["BranchModel"] =  ApInvoiceModel::find($id)->Branch()->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }


    function Create($data)
    {
        try {
            //Model Initialized 
            $model = new ApInvoiceModel;
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

                $model = ApInvoiceModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by =  $this->CurrentUserId();
            }

            $currentUser = $this->currentUser();

            $model->company_id = $currentUser->company_id;
            $model->branch_id = $currentUser->branch_id;



            $model->purchase_id = $this->data['purchase_id'];
            $model->supplier_id = $this->data['supplier_id'];
            $model->receive_id = $this->data['receive_id'];
            $model->inv_no = $this->data['inv_no'];
            $model->inv_status = $this->data['inv_status'];
            $model->inv_date = $this->data['inv_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];
            $model->paid_amt = $this->data['paid_amt'];
            $model->outstanding_amt = $this->data['outstanding_amt'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {

                session(['transId' => $model->id]);

                if (array_key_exists("ApInvoiceDetailModel", $this->data) && count($this->data['ApInvoiceDetailModel']) > 0) {

                    $model->ApInvoiceDetails()->delete();
                    $invDetails = [];

                    foreach ($this->data['ApInvoiceDetailModel'] as $key => $value) {
                        $detailModel = new ApInvoiceDetailModel;
                        $detailModel->company_id = $model->company_id;
                        $detailModel->branch_id = $model->branch_id;
                        $detailModel->supplier_id = $model->supplier_id;
                        $detailModel->purchase_id = $model->purchase_id;
                        $detailModel->receive_id = $model->receive_id;
                        $detailModel->ap_invoice_id = $model->id;
                        $detailModel->ptp_id = $value['ptp_id'];
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

                        $invDetails[] = $detailModel;
                    }
                    try {
                        $model->ApInvoiceDetails()->saveMany($invDetails);
                    } catch (\Exception $e) {
                        dd($e->getMessage());
                    }
                }
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            $this->intlSrvError();
        }
    }



    function UpdateOutstandingInv($data)
    {
        try {
            //Model Initialized 
            $model = new ApInvoiceModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'id.required' => 'Please enter invoice number',
                // 'po_no.required' => 'The po number is required.',                
                // 'po_no.unique' => 'The po number has already been taken.',                
            ];
            //Set Data Validation Rules
            $this->rules = [
                'id' => 'required',
                //'po_no' => 'required|unique:t_scm_purchase|max:50',

            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ApInvoiceModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                DB::rollBack();
                $this->message = "Transaction invoice details not found";
                $this->trxnNotCompleted();
                return;
            }

            $model->paid_amt = $model->paid_amt + $this->data['paid_amt'];
            $model->outstanding_amt = $model->outstanding_amt -  $this->data['paid_amt'];

            if ($model->outstanding_amt <= 0) {
                $model->inv_status = "C";
            }

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }
        return false;
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
        if (ApInvoiceModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
