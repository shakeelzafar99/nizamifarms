<?php

namespace App\Models\FIN;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArInvoiceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_ar_invoice';
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
        'receive_id',
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

    public function ArInvoiceDetails()
    {
        return $this->hasMany(ArInvoiceDetailModel::class, 'ar_invoice_id', 'id');
    }

    public function Order()
    {
        return $this->hasMany(OrderModel::class, 'id', 'order_id');
    }

    public function Customer()
    {
        return $this->hasOne(CustomerModel::class, 'id', 'cust_id');
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
            
            $this->data = ArInvoiceModel::join('t_crm_order', 't_crm_order.id', '=', 't_fin_ar_invoice.order_id')
                ->join('t_crm_account_customer', 't_crm_account_customer.id', '=', 't_fin_ar_invoice.cust_id')
                ->where($this->filter)
                ->where($this->cFilter)
                ->whereLike([
                    'inv_no',
                    'inv_date',
                    'po_no',
                    'po_date'
                ], $this->searchTerm)->orderBy($this->column, $this->direction)
                ->select('t_fin_ar_invoice.*',  't_crm_account_customer.cust_no', 't_crm_account_customer.company_name',  't_crm_order.order_no', 't_crm_order.order_date')
                ->paginate($this->pageSize)
                ->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function GetOutstanding($company_id, $branch_id, $supplier_id) //All record
    {
        try {
            $whereData = [
                ['t_fin_ar_invoice.company_id', $company_id],
                ['t_fin_ar_invoice.branch_id', $branch_id],
                ['t_fin_ar_invoice.supplier_id', $supplier_id],
                ['t_fin_ar_invoice.inv_status', "O"],
                ['t_fin_ar_invoice.outstanding_amt', '>', 0]
            ];
            $this->data = ArInvoiceModel::join('t_scm_supplier', 't_scm_supplier.id', '=', 't_fin_ar_invoice.supplier_id')
                ->join('t_scm_purchase', 't_scm_purchase.id', '=', 't_fin_ar_invoice.purchase_id')
                ->where($whereData)->select('t_fin_ar_invoice.*', 't_scm_supplier.supplier_name', 't_scm_purchase.po_no', 't_scm_purchase.po_date')->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }

    function Get($id) //Single  
    {
        $this->data = ArInvoiceModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetDetail($id) //All record
    {
        try {
            $this->data = ArInvoiceModel::find($id)->toArray();
            $this->data["ApInvoiceDetailModel"] =  ArInvoiceModel::find($id)->ArInvoiceDetails()->get()->toArray();
            $this->data["OrderModel"] =  ArInvoiceModel::find($id)->Order()->get()->toArray();
            $this->data["CustomerModel"] =  ArInvoiceModel::find($id)->Customer()->get()->toArray();
            $this->data["CompanyModel"] =  ArInvoiceModel::find($id)->Company()->get()->toArray();
            $this->data["CompanyConfigModel"] =  ArInvoiceModel::find($id)->CompanyConfig()->get()->toArray();
            $this->data["BranchModel"] =  ArInvoiceModel::find($id)->Branch()->get()->toArray();
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }


    function GetCountByBranch($id) //Single  
    {
        $list = ArInvoiceModel::where('branch_id', '=', $id)->get();
        return $list->count();
    }




    function Create($data)
    {
        try {
            //Model Initialized 
            $model = new ArInvoiceModel;
            // //Set Data Validation Error Messages

            $this->data = $data;
            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {

                $model = ArInvoiceModel::find($this->data['id']);
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
            $model->inv_no = $this->data['inv_no'];
            $model->inv_date = $this->data['inv_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];
            $model->cust_id = $this->data['cust_id'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {

                if (array_key_exists("ArInvoiceDetailModel", $this->data) && count($this->data['ArInvoiceDetailModel']) > 0) {

                    $model->ArInvoiceDetails()->delete();
                    $invDetails = [];

                    foreach ($this->data['ArInvoiceDetailModel'] as $key => $value) {

                        $detailModel = new ArInvoiceDetailModel;
                        $detailModel->company_id = $model->company_id;
                        $detailModel->branch_id = $model->branch_id;
                        $detailModel->order_id = $model->order_id;
                        $detailModel->ar_invoice_id = $model->id;
                        $detailModel->cust_id = $value['cust_id'];
                        $detailModel->ptp_id = $value['ptp_id'];
                        $detailModel->is_part_worn = $value['is_part_worn'];
                        $detailModel->part_id = $value['part_id'];
                        $detailModel->service_id = $value['service_id'];
                        $detailModel->description = $value['description'];
                        $detailModel->quantity = $value['quantity'];
                        $detailModel->unit_cost = $value['purchase_price'];
                        $detailModel->tot_cost = $detailModel->quantity * $value['purchase_price'];
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
                    $model->ArInvoiceDetails()->saveMany($invDetails);
                }
                session(['transId' => $model->id]);
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
        if (ArInvoiceModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
