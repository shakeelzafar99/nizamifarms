<?php

namespace App\Models\SCM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;
use App\Models\PDM\ProductModel;
use App\Models\PDM\BrandModel;
use App\Models\PDM\SizeModel;
use App\Models\PDM\PartModel;
use App\Models\PDM\ProductTreadPatternsModel;
use App\Models\CRM\CompanyModel;
use App\Models\CRM\CompanyConfigModel;
use App\Models\CRM\BranchModel;


class StockModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_scm_stock';
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
        'product_id',
        'ptp_id',
        'is_part_worn',
        'part_id',
        'sale_price',
        'stock_value',
        'total_purchase',
        'tot_purch_val',
        'description',

    ];

    protected $map = [
        'id',
        'company_id',
        'branch_id',
        'product_id',
        'ptp_id',
        'is_part_worn',
        'part_id',
        'sale_price',
        'stock_value',
        'total_purchase',
        'tot_purch_val',
        'description',
    ];

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

    public function Product()
    {
        return $this->hasOne(ProductModel::class, 'id', 'product_id')->with(['Brand', 'Size']);
    }
    public function Brand()
    {
        return $this->belongsTo(BrandModel::class, 'brand_id');
    }

    public function Size()
    {
        return $this->belongsTo(SizeModel::class, 'size_id');
    }
    public function ProductTreadPatterns()
    {
        return $this->hasOne(ProductTreadPatternsModel::class, 'id', 'ptp_id');
    }

    public function Part()
    {
        return $this->hasOne(PartModel::class, 'id', 'part_id');
    }

    public function StockDetails()
    {
        return $this->hasMany(StockDetailModel::class, 'stock_id', 'id');
    }

    function ProductList($data) //All record
    {
        //Set Filters
        $this->listRequest($data);
        //Set Filters   
        // DB::enableQueryLog();
        try {
            if ($this->flag === "AllProduct") {

                $this->branchId = $this->filter["branch_id"];
                unset($this->filter["company_id"]);
                unset($this->filter["branch_id"]);
                $this->data = ProductTreadPatternsModel::join('t_pdm_product', 't_pdm_product.id', '=', 't_pdm_product_tread_pattern.product_id')
                    ->join('t_pdm_size', 't_pdm_size.id', '=', 't_pdm_product.size_id')
                    ->join('t_pdm_brand', 't_pdm_brand.id', '=', 't_pdm_product.brand_id')
                    ->leftJoin('t_scm_stock', function ($join) {
                        $join->on('t_scm_stock.ptp_id', '=', 't_pdm_product_tread_pattern.id');
                        $join->on('t_scm_stock.branch_id', '=', DB::raw($this->branchId));
                    })
                    ->where($this->filter)
                    ->select('t_pdm_product_tread_pattern.id', 't_scm_stock.is_part_worn',  't_scm_stock.stock_value', 't_scm_stock.sale_price', 't_scm_stock.total_purchase', 't_scm_stock.tot_purch_val', 't_scm_stock.tot_sold', 't_scm_stock.tot_sold_val', 't_pdm_product_tread_pattern.size_num', 't_pdm_product_tread_pattern.description', 't_pdm_product_tread_pattern.pattern', 't_pdm_product_tread_pattern.speed_load', 't_pdm_product_tread_pattern.fuel_efficient', 't_pdm_product_tread_pattern.wet_grip', 't_pdm_product_tread_pattern.noise', 't_pdm_brand.brand_name', 't_pdm_size.size_desc', 't_pdm_product.ean', 't_pdm_product.vehicle_type', 't_pdm_product.brand_category', 't_pdm_product.season')
                    ->get()->toArray();
            } else {
                $this->data = StockModel::join('t_pdm_product_tread_pattern', 't_pdm_product_tread_pattern.id', '=', 't_scm_stock.ptp_id')
                    ->join('t_pdm_product', 't_pdm_product.id', '=', 't_pdm_product_tread_pattern.product_id')
                    ->join('t_pdm_size', 't_pdm_size.id', '=', 't_pdm_product.size_id')
                    ->join('t_pdm_brand', 't_pdm_brand.id', '=', 't_pdm_product.brand_id')
                    ->where($this->filter)
                    ->whereLike(['ean'], $this->searchTerm)
                    ->select('t_scm_stock.id', 't_scm_stock.is_part_worn',  't_scm_stock.stock_value', 't_scm_stock.sale_price', 't_scm_stock.total_purchase', 't_scm_stock.tot_purch_val', 't_scm_stock.tot_sold', 't_scm_stock.tot_sold_val', 't_pdm_product_tread_pattern.size_num', 't_pdm_product_tread_pattern.description', 't_pdm_product_tread_pattern.pattern', 't_pdm_product_tread_pattern.speed_load', 't_pdm_product_tread_pattern.fuel_efficient', 't_pdm_product_tread_pattern.wet_grip', 't_pdm_product_tread_pattern.noise', 't_pdm_brand.brand_name', 't_pdm_size.size_desc', 't_pdm_product.ean', 't_pdm_product.vehicle_type', 't_pdm_product.brand_category', 't_pdm_product.season')
                    ->orderBy($this->column, $this->direction)
                    ->paginate($this->pageSize)->toArray();
            }

            // dd(DB::getQueryLog());
        } catch (\Exception $e) {
            dd($e->getMessage());
        }

        return $this->setResponse();
    }

    function PartList($data) //All record
    {
        $this->listRequest($data);
        try {
            $this->data = StockModel::join('t_pdm_part', 't_pdm_part.id', '=', 't_scm_stock.part_id')
                ->where($this->filter)
                ->whereLike(['part_name', 'description'], $this->searchTerm)
                ->orderBy($this->column, $this->direction)
                ->select('t_scm_stock.id',  't_scm_stock.stock_value', 't_scm_stock.sale_price', 't_scm_stock.total_purchase', 't_scm_stock.tot_purch_val', 't_scm_stock.tot_sold', 't_scm_stock.tot_sold_val', 't_pdm_part.part_name', 't_pdm_part.description')
                ->orderBy($this->column, $this->direction)
                ->paginate($this->pageSize)->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }


    public function Get($id) //Single  
    {
        try {
            // Retrieve the stock model once with eager-loaded relationships
            $stock = StockModel::with([
                'StockDetails' => function ($query) {
                    $query->where('stock_value', '>', 0);
                },
                'Product',
                'ProductTreadPatterns',
                'Part',
                'Company',
                'Branch'
            ])->findOrFail($id)->toArray();


            $stock['StockDetailModel'] = $stock['stock_details'];
            unset($stock['stock_details']); // Remove old key if needed 

            $stock['ProductModel'] = $stock['product'];
            unset($stock['product']); // Remove old key if needed 

            $stock['ProductModel']["SizeModel"] = $stock['ProductModel']["size"];
            unset($stock['ProductModel']["size"]); // Remove old key if needed 

            $stock['ProductModel']["BrandModel"] = $stock['ProductModel']["brand"];
            unset($stock['ProductModel']["brand"]); // Remove old key if needed 

            $stock['ProductTreadPatternModel'] = $stock['product_tread_patterns'];
            unset($stock['product_tread_patterns']); // Remove old key if needed 

            $stock['PartModel'] = $stock['part'];
            unset($stock['part']); // Remove old key if needed 

            $stock['CompanyModel'] = $stock['company'];
            unset($stock['company']); // Remove old key if needed 

            $stock['BranchModel'] = $stock['branch'];
            unset($stock['branch']); // Remove old key if needed 



            // Convert to array
            $this->data = $stock;
            return $this->setResponse();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }




    function CheckStock($data) //Single  
    {

        //Set Filters
        $this->listRequest($data);
        //Set Filters 
        return StockModel::where($this->filter)->first();
    }

    function Create($data)
    {
        try {

            //Model Initialized 
            $model = new StockModel;
            $stock_value = 0;
            $tot_purch_val = 0;
            $total_purchase = 0;
            $is_sprice_update = false;

            //Set Data Validation Error Messages
            // $this->err_msgs = [
            //     'stock_value.required' => 'Please enter stock value',
            //     'stock_value.unique' => 'The stock value has already been taken.',
            // ];
            // //Set Data Validation Rules
            // $this->rules = [
            //     'stock_value' => 'required|unique:t_scm_stock|max:50',
            //     'sale_price' => 'required'
            // ];
            $this->data = $data;


            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
               
                $model = StockModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $model->updated_by = $this->CurrentUserId();

                if (isset($this->data['is_sprice_update']) && $this->data['is_sprice_update'] === "Y") {
                    $model->sale_price  = $this->data['sale_price'];
                    $stock_value        = $model->stock_value;
                    $total_purchase     = $model->total_purchase;
                    $tot_purch_val      = $model->tot_purch_val;
                    $is_sprice_update = true;
                } else {

                    $stock_value =  $this->data['stock_value'];
                    $total_purchase = $model->total_purchase;
                    $tot_purch_val = $model->tot_purch_val;

                    if (isset($this->data['total_purchase']) && isset($this->data['tot_purch_val'])) {
                        $total_purchase = $model->total_purchase + $this->data['stock_value'];
                        $tot_purch_val = $model->tot_purch_val + $this->data['tot_purch_val'];
                    }

                    if (isset($this->data['tot_sold']) && isset($this->data['tot_sold_val'])) {
                        $model->tot_sold = $this->data['tot_sold'];
                        $model->tot_sold_val = $this->data['tot_sold_val'];
                    }
                }
            } else {
                $model->created_by      = $this->CurrentUserId();
                $model->is_part_worn    = $this->data['is_part_worn'];
                $stock_value        = $this->data['stock_value'];
                $total_purchase     = $this->data['stock_value'];
                $tot_purch_val      = $this->data['tot_purch_val'];
                $model->company_id  = $this->data['company_id'];
                $model->branch_id   = $this->data['branch_id'];
                $model->product_id  = $this->data['product_id'];
                $model->ptp_id      = $this->data['ptp_id'];
                $model->part_id     = $this->data['part_id'];
            }

            $model->stock_value = $stock_value;
            $model->tot_purch_val = $tot_purch_val;
            $model->total_purchase = $total_purchase;

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }


            if ($model->save()) {
                $this->transId = $model->id;
                //Only run in case of purchase order 
                if (isset($this->data['purchase_price']) && !$is_sprice_update) {
                    $stock_d_value = 0;
                    $d_tot_purch_val = 0;
                    $d_total_purchase = 0;
                    $stockData = [];
                    $stockData["id"] = 0;
                    //Update Stock Details
                    $stockDetail = new StockDetailModel();
                    //Check Existing Stock
                    $find["filter"] = [];
                    $find["filter"]["company_id"]        = $model->company_id;
                    $find["filter"]["branch_id"]         = $model->branch_id;
                    $find["filter"]["is_part_worn"]      = $model->is_part_worn;
                    $find["filter"]["stock_id"]          = $model->id;
                    $find["filter"]["purchase_price"]    = $this->data['purchase_price'];

                    $checkStock = $stockDetail->CheckStock($find);
                    if ($checkStock != null) {
                        $stockData["id"] = $checkStock["id"];
                        $stock_d_value = $checkStock["stock_value"];
                        $d_tot_purch_val = $checkStock["tot_purch_val"];
                        $d_total_purchase = $checkStock["total_purchase"];
                    }
                    //Check Existing Stock  
                    //Set Data                        
                    $stockData["stock_id"]          = $model->id;
                    $stockData["company_id"]        = $model->company_id;
                    $stockData["branch_id"]         = $model->branch_id;
                    $stockData["product_id"]        = $model->product_id;
                    $stockData["ptp_id"]            = $model->ptp_id;
                    $stockData["is_part_worn"]      = $model->is_part_worn;
                    $stockData["part_id"]           = $model->part_id;
                    $stockData["stock_value"]       = ($stock_d_value + $this->data['stock_value']);
                    $stockData["purchase_price"]    = $this->data['purchase_price'];
                    $stockData["tot_purch_val"]     = ($d_tot_purch_val + $tot_purch_val);
                    $stockData["total_purchase"]    = ($d_total_purchase + $total_purchase);

                    $stockDetail->Create($stockData);
                    //Update Stock Details
                }
                //Only run in case of purchase order 
                $this->trxnCompleted();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
            $this->intlSrvError();
        }
        // $this->intlSrvError();
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
