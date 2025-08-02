<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use DB;

class ProductTreadPatternsModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_product_tread_pattern';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true;

    protected $keyword = '';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */


    protected $fillable = [
        'id',
        'size_id',
        'size_desc',
        'size_num',
        'brand_id',
        'brand_name',
        'product_id',
        'pattern',
        'speed_load',
        'fuel_efficient',
        'wet_grip',
        'noise',
        'pattern_img',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'size_id',
        'size_desc',
        'size_num',
        'brand_id',
        'brand_name',
        'product_id',
        'pattern',
        'speed_load',
        'fuel_efficient',
        'wet_grip',
        'noise',
        'pattern_img',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = ProductTreadPatternsModel::where($this->filter)->whereLike(['pattern', 'speed_load', 'fuel_efficient', 'wet_grip', 'noise', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = ProductTreadPatternsModel::where($this->filter)->whereLike(['pattern', 'speed_load', 'fuel_efficient', 'wet_grip', 'noise', 'description'], $this->searchTerm)->orderBy("pattern", "asc")->get()->toArray();
        }
        return $this->setResponse();
    }

    function Autocomplete($company_id, $branch_id, $value, $value1, $value2)
    {
        $this->keyword = $this->numOnly($value);
        try {
            if ($value1 === "STOCK") {
                //DB::enableQueryLog();
                $this->data = ProductTreadPatternsModel::join('t_scm_stock', 't_scm_stock.ptp_id', '=', 't_pdm_product_tread_pattern.id')
                    ->where("t_pdm_product_tread_pattern.is_active", "Y")
                    ->where("t_pdm_product_tread_pattern.size_num", "like", '%' . $this->keyword . '%')
                    ->where("t_scm_stock.stock_value", ">", 0)
                    ->where("t_scm_stock.company_id",  $company_id)
                    ->where("t_scm_stock.branch_id",  $branch_id)
                    ->where("t_scm_stock.is_part_worn",  $value2)
                    ->select('t_pdm_product_tread_pattern.*', 't_scm_stock.stock_value', 't_scm_stock.sale_price')->get()->toArray();
                //  dd(DB::getQueryLog());
            } else {
                $this->data =  ProductTreadPatternsModel::where("is_active", "Y")->where("size_num", "like", '%' . $this->keyword . '%')->get()->toArray();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }



    function Get($id) //Single  
    {
        $this->data = ProductTreadPatternsModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {

        //Model Initialized 
        $model = new ProductTreadPatternsModel;

        //Set Data Validation Error Messages
        $this->err_msgs = [
            'size_id.required' => 'Please enter size',
            'brand_id.required' => 'Please enter brand',
            'product_id.required' => 'Please enter product',
            'pattern.required' => 'Please enter pattern',
            'speed_load.required' => 'Please enter speed load',
            'fuel_efficient.required' => 'Please enter fuel efficient',
            'wet_grip.required' => 'Please enter wet grip',
            'noise.required' => 'Please enter noise',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'size_id' => 'required',
            'brand_id' => 'required',
            'product_id' => 'required',
            'pattern' => 'required',
            'speed_load' => 'required',
            'fuel_efficient' => 'required',
            'wet_grip' => 'required',
            'noise' => 'required'

        ];
        $this->data = $data;
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = ProductTreadPatternsModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }
        $model->size_id = $this->data['size_id'];
        $model->brand_id = $this->data['brand_id'];

        $size = new SizeModel();
        $sizeData = $size->find($model->size_id);
        if ($sizeData == null) {
            $this->dataNotFound();
            return $this->setResponse();
        }


        $brand = new BrandModel();
        $brandData = $brand->find($model->brand_id);
        if ($brandData == null) {
            $this->dataNotFound();
            return $this->setResponse();
        }


        $model->size_num = $sizeData->size_num;
        $model->product_id = $this->data['product_id'];
        $model->pattern = $this->data['pattern'];
        $model->speed_load = $this->data['speed_load'];
        $model->fuel_efficient = $this->data['fuel_efficient'];
        $model->wet_grip = $this->data['wet_grip'];
        $model->noise = $this->data['noise'];
        $model->pattern_img = $this->data['pattern_img'];
        $model->description =   $sizeData->size_desc . " " . $brandData->brand_name . " " . $this->data['pattern'] . " " . $this->data['speed_load'];

        if (!$this->dataValidation()) {
            return $this->setResponse();
        }


        //Upload Logo
        $this->uploadDir  = "products/pattern/";

        if ($this->data['imgSource'] !== null) {
            $this->fileSource = $this->data['imgSource'] !== null ? $this->data['imgSource'] : '';
            $this->oldFile =  $model->pattern_img !== null ? $model->pattern_img : '';
            $model->pattern_img      = $this->base64ImageUpload();
        }
        //Upload Logo 

        try {

            if ($model->save()) {
                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (\Exception $e) {
            $this->err_msgs[] = $e->getMessage();     
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {

        $model = ProductTreadPatternsModel::find($id);
        if ($model) {
            $this->oldFile =  $model->pattern_img !== null ? $model->pattern_img : '';
            if ($model->delete()) {
                $isUsedWithOther =     ProductTreadPatternsModel::where('pattern_img',$this->oldFile)->where("id",'!=', $id)->get();
                if(!$isUsedWithOther){
                    $this->removeUploadedImage();
                }                
                $this->delTrxnCompleted();
                return $this->setResponse();
            }
        } else {
            $this->trxnNotCompleted();
            $this->message = "Recored not found";
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
