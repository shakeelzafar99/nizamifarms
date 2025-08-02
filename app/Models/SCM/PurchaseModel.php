<?php

namespace App\Models\SCM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\CompanyConfigModel;
use App\Models\CRM\BranchModel;
use App\Traits\FIN\ApTransaction;
use Illuminate\Support\Facades\DB;
use Exception;

class PurchaseModel extends BaseModel
{
    use HasFactory, Notifiable, ApTransaction;
    protected $table = 't_scm_purchase';
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

    public function PurchaseDetails()
    {
        return $this->hasMany(PurchaseDetailModel::class, 'purchase_id', 'id');
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
        $this->listRequest($data);
        try {
            $this->data = PurchaseModel::join('t_scm_supplier', 't_scm_supplier.id', '=', 't_scm_purchase.supplier_id')->where($this->filter)->whereLike([
                'po_no',
                'po_date',
                'description',
                'tot_qty',
                'tot_price',
                'vat_rate',
                'tot_vat',
                'tot_price_vat',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->select('t_scm_purchase.*', 't_scm_supplier.supplier_name')->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            // Handle the exception
            dd($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
        return $this->setResponse();
    }
    function GetCountByBranch($id) //Single  
    {
        $list = PurchaseModel::where('branch_id', '=', $id)->get();
        return $listCount = $list->count();
    }
    function GetDetail0($id) //All record  
    {
        try {
            $purchase = PurchaseModel::with([
                'Supplier',
                'Company',
                'CompanyConfig',
                'Branch',
                'PurchaseDetails'
            ])->findOrFail($id)->toArray();

            return $this->setResponse();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    function Get($id) //Single  
    {
        try {
            $purchase = PurchaseModel::with([
                'Supplier',
                'Company',
                'CompanyConfig',
                'Branch',
                'PurchaseDetails'
            ])->findOrFail($id)->toArray();

            $purchase['SupplierModel'] = $purchase['supplier'];
            unset($purchase['supplier']); // Remove old key if needed 

            $purchase['CompanyModel'] = $purchase['company'];
            unset($purchase['company']); // Remove old key if needed 

            $purchase['CompanyConfigModel'] = $purchase['company_config'];
            unset($purchase['company_config']); // Remove old key if needed

            $purchase['BranchModel'] = $purchase['branch'];
            unset($purchase['branch']); // Remove old key if needed 

            $purchase['PurchaseDetailModel'] = array_values($purchase['purchase_details'] ?? []);
            unset($purchase['purchase_details']); // Remove old key if needed  
            $this->data = $purchase;
            return $this->setResponse();
        } catch (\Exception $e) {
            $this->intlSrvError();
            $this->errors[0] =  $e->getMessage();
            return $this->setResponse();
        }
    }

    function Store($data)
    {
        // Initialize model
        $model = new PurchaseModel;

        // Set validation error messages and rules
        $this->err_msgs = [
            'po_date.required' => 'Please enter po date',
            'supplier_id.required' => 'Please enter supplier details',
        ];
        $this->rules = [
            'po_date' => 'required',
            'supplier_id' => 'required',
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = PurchaseModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['po_no'] =  $this->rules['po_no'] . ',po_no,' . $model->id;
            $model->updated_by = $this->CurrentUserId();
        } else {
            $model->created_by = $this->CurrentUserId();
        }

        $model->company_id = $this->data['company_id'];
        $model->branch_id = $this->data['branch_id'];

        DB::beginTransaction(); // Start the transaction

        try {
            // PO Number
            $this->cid = $model->company_id;
            $this->bid = $model->branch_id;
            $this->getApPoNo(); // Generate PO number

            $model->supplier_id = $this->data['supplier_id'];
            $model->po_no = $this->newNum;
            $model->po_date = $this->data['po_date'];
            $model->description = $this->data['description'];
            $model->tot_qty = $this->data['tot_qty'];
            $model->tot_price = $this->data['tot_price'];
            $model->vat_rate = $this->data['vat_rate'];
            $model->tot_vat = $this->data['tot_vat'];
            $model->tot_price_vat = $this->data['tot_price_vat'];

            // Validate data
            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            // Save the main model
            if ($model->save()) {

                // Process Purchase Details if provided
                if (isset($this->data['PurchaseDetailData']) && is_array($this->data['PurchaseDetailData']) && count($this->data['PurchaseDetailData']) > 0) {

                    // Delete previous purchase details
                    $model->PurchaseDetails()->delete();

                    // Initialize array for new purchase details
                    $purchaseDetails = [];
                    foreach ($this->data['PurchaseDetailData'] as $key => $value) {
                        $detailModel = new PurchaseDetailModel;
                        $detailModel->company_id = $model->company_id;
                        $detailModel->branch_id = $model->branch_id;
                        $detailModel->supplier_id = $model->supplier_id;
                        $detailModel->purchase_id = $model->id;
                        $detailModel->product_id = $value['product_id'];
                        $detailModel->ptp_id = $value['ptp_id'];
                        $detailModel->is_part_worn = $value['is_part_worn'];
                        $detailModel->part_id = $value['part_id'];
                        $detailModel->description = $value['description'];
                        $detailModel->quantity = $value['quantity'];
                        $detailModel->vat_rate = $this->data['vat_rate'];
                        $detailModel->unit_price = $value['unit_price'];
                        $detailModel->unit_price_vat_val = $value['unit_price_vat_val'];
                        $detailModel->unit_price_vat = $value['unit_price_vat'];
                        $detailModel->tot_price = $value['tot_price'];
                        $detailModel->tot_price_vat_val = $value['tot_price_vat_val'];
                        $detailModel->tot_price_vat = $value['tot_price_vat'];
                        $detailModel->created_by = $this->CurrentUserId();

                        $purchaseDetails[] = $detailModel;
                    }

                    // Save purchase details
                    $model->PurchaseDetails()->saveMany($purchaseDetails);

                    // Set response and commit the transaction
                    $this->data["id"] = $model->id;
                    $this->trxnCompleted();
                    DB::commit(); // Commit the transaction
                    return $this->setResponse();
                }
            }
        } catch (Exception $e) {
            // Rollback the transaction on error
            DB::rollback();
            $this->intlSrvError(); // Handle error response
            return $this->setResponse();
        }

        // If all else fails
        $this->intlSrvError();
        $this->errors[0] = "An error occurred while creating the purchase. Please try again or contact the administrator.";
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (PurchaseModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
