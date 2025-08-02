<?php

namespace App\Models\SCM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;
use App\Models\PDM\ProductModel;
use App\Models\PDM\PartModel;

class StockDetailModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_scm_stock_detail';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'stock_id',
        'company_id',
        'branch_id',
        'product_id',
        'ptp_id',
        'is_part_worn',
        'part_id',
        'purchase_price',
        'stock_value',
        'description',
    ];

    protected $map = [
        'stock_id',
        'company_id',
        'branch_id',
        'product_id',
        'ptp_id',
        'is_part_worn',
        'part_id',
        'purchase_price',
        'stock_value',
        'description',
    ];


    function ProductList($data) //All record
    {
        //Set Filters
        $this->listRequest($data);
        //Set Filters   

        try {
            $this->data = StockDetailModel::join('t_pdm_product_tread_pattern', 't_pdm_product_tread_pattern.id', '=', 't_scm_stock.ptp_id')
                ->join('t_pdm_product', 't_pdm_product.id', '=', 't_pdm_product_tread_pattern.product_id')
                ->join('t_pdm_size', 't_pdm_size.id', '=', 't_pdm_product.size_id')
                ->join('t_pdm_brand', 't_pdm_brand.id', '=', 't_pdm_product.brand_id')
                ->where($this->filter)  
                ->whereLike(['ean'], $this->searchTerm)
                ->orderBy($this->column, $this->direction)
                ->select('t_pdm_product_tread_pattern.*', 't_pdm_brand.brand_name', 't_pdm_size.size_desc', 't_pdm_product.ean', 't_pdm_product.vehicle_type', 't_pdm_product.brand_category', 't_pdm_product.season',  't_scm_stock.stock_value', 't_scm_stock.sale_price')
                ->orderBy($this->column, $this->direction)
                ->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }

        return $this->setResponse();
    }

    function PartList($data) //All record
    {
        $this->listRequest($data);
        try {
            $this->data = StockDetailModel::join('t_pdm_part', 't_pdm_part.id', '=', 't_scm_stock.part_id')
                ->where($this->filter)
                ->whereLike(['part_name', 'description'], $this->searchTerm)
                ->orderBy($this->column, $this->direction)
                ->select('t_pdm_part.*', 't_scm_stock.stock_value', 't_scm_stock.sale_price')
                ->orderBy($this->column, $this->direction)
                ->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }


    function Get($id) //Single  
    {
        $this->data = StockDetailModel::find($id)->toArray();
        return $this->setResponse();
    }

    function CheckStock($data) //Single  
    {

        //Set Filters
        $this->listRequest($data);
        //Set Filters 
        return StockDetailModel::where($this->filter)->first();
    }

    function CheckStockList($data) //Single  
    {

        //Set Filters
        $this->listRequest($data);
        //Set Filters 
        return StockDetailModel::where($this->filter)->where('stock_value', '>', 0)->get()->toArray();
    }

    function Create($data)
    {
        try {

            //Model Initialized 
            $model = new StockDetailModel;
            $stock_value = 0;

            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = StockDetailModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();
            } else {
                $model->created_by      = $this->CurrentUserId();
                $model->stock_id        = $this->data['stock_id'];
                $model->is_part_worn    = $this->data['is_part_worn'];
                $model->purchase_price  = $this->data['purchase_price'];
                $model->company_id      = $this->data['company_id'];
                $model->branch_id       = $this->data['branch_id'];
                $model->product_id      = $this->data['product_id'];
                $model->ptp_id          = $this->data['ptp_id'];
                $model->part_id         = $this->data['part_id'];
            }

            $model->stock_value = $this->data['stock_value'];
            if (isset($this->data['total_purchase']) && isset($this->data['tot_purch_val'])) {
                $model->total_purchase = $this->data['total_purchase'];
                $model->tot_purch_val = $this->data['tot_purch_val'];
            }

            if (isset($this->data['tot_sold']) && isset($this->data['tot_sold_val'])) {
                $model->tot_sold = $this->data['tot_sold'];
                $model->tot_sold_val = $this->data['tot_sold_val'];
            }

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {
                $this->transId = $model->id;
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
            $this->intlSrvError();
        }
        $this->intlSrvError();
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
        if (StockModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
