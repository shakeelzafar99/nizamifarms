<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Dotenv\Util\Str;
use SebastianBergmann\Environment\Console;
use App\Models\PDM\SizeModel;

class ProductModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_product';
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
        'size_brand_ck',
        'size_id',
        'size_desc',
        'brand_id',
        'brand_name',
        'ean',
        'vehicle_type',
        'brand_category',
        'season',
        'is_runflat',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'size_brand_ck',
        'size_id',
        'size_desc',
        'brand_id',
        'brand_name',
        'ean',
        'vehicle_type',
        'brand_category',
        'season',
        'is_runflat',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    public function Size()
    {
        return $this->belongsTo(SizeModel::class, 'size_id', 'id');
    }

    public function Brand()
    {
        return $this->belongsTo(BrandModel::class, 'brand_id', 'id');
    }

    function Autocomplete($val, $val1)
    {
        $this->keyword = $this->numOnly($val);
        try {

            $this->data =  ProductModel::select('id', 'description')->where("is_active", "Y")
                ->whereIn('size_id',  function ($query) {
                    $query->select('id')
                        ->from(with(new SizeModel())->getTable())
                        ->where("size_num", "like", '%' . $this->keyword . '%');
                })->orderBy("description")->get()->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }


    function List($data) //All record
    {

        try {
            $this->listRequest($data);
            if ($this->flag === "Pagination") {
                if ($this->column === "size_desc") {
                    $this->column = "size_num";
                }
                $this->data = ProductModel::join('t_pdm_brand', 't_pdm_brand.id', '=', 't_pdm_product.brand_id')->join('t_pdm_size', 't_pdm_size.id', '=', 't_pdm_product.size_id')
                    ->where($this->filter)->whereLike(['vehicle_type', 'brand_category', 'season'], $this->searchTerm)->orderBy($this->column, $this->direction)
                    ->select('t_pdm_product.*', 't_pdm_brand.brand_name', 't_pdm_size.size_desc')->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
            } else {
                $this->data["data"] = ProductModel::where($this->filter)->whereLike(['vehicle_type', 'brand_category', 'season', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function Get($id, $brand_id, $size_id) //Single  
    {
        $sizeModel = new SizeModel();
        $brandModel = new BrandModel();
        if ($id > 0) {
            $this->data = ProductModel::where("id", $id)->get()->toArray();
            if($this->data){
                $sizeData = $sizeModel->Get($this->data[0]["size_id"]);  
                $this->data[0]["SizeModel"] =   $sizeData->data;    
                $brandData = $brandModel->Get($this->data[0]["brand_id"]);  
                $this->data[0]["BrandModel"] =   $brandData->data; 
            }
        } else { 
            $this->data = ProductModel::where("brand_id", $brand_id)->where("size_id", $size_id)->get()->toArray();
            if($this->data){
                $sizeData = $sizeModel->Get($this->data[0]["size_id"]);  
                $this->data[0]["SizeModel"] =   $sizeData->data;    
                $brandData = $brandModel->Get($this->data[0]["brand_id"]);  
                $this->data[0]["BrandModel"] =   $brandData->data; 
            } 
        }
        return $this->setResponse();
    }

    function Store($data)
    {

        //Model Initialized 
        $model = new ProductModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'size_brand_ck.unique' => 'This size is already exist with seleted brand.',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'size_id' => 'required',
            'brand_id' => 'required',
            'vehicle_type' => 'required',
            'brand_category' => 'required',
            'season' => 'required',
            'is_runflat' => 'required',
            'size_brand_ck' => 'required|max:100|unique:t_pdm_product,size_brand_ck',

        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = ProductModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['size_brand_ck'] =  $this->rules['size_brand_ck'] . ',' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
            $this->data["size_brand_ck"] = (string)$this->data['size_id'] . "-" . (string)$this->data['brand_id'];
            $model->size_brand_ck =  $this->data["size_brand_ck"];
        }



        $model->size_id = $this->data['size_id'];
        $model->brand_id = $this->data['brand_id'];
        $model->ean = $this->data['ean'];
        $model->vehicle_type = $this->data['vehicle_type'];
        $model->brand_category = $this->data['brand_category'];
        $model->season = $this->data['season'];
        $model->is_runflat = $this->data['is_runflat'];
        $model->is_commercial = $this->data['is_commercial'];
        $model->description = $this->data['description'];

        //$size = $model->Size()->where('id', $model->size_id)->first();
        //$brand = $model->Brand()->where('id', $model->brand_id)->first();
        //$model->short_desc =  'The ' . $size->size_desc . ' ' . $brand->brand_name . ' has a diameter of ' . $size->diameter . '", a width of ' . $size->section_width_inch . '", mounts on a ' . $size->size . '" rim and has ' . $size->revs_mile . ' revolutions per mile. It weighs ' . $model->weight . ', has a max load of ' . $model->max_load . ', a maximum air pressure of ' . $model->max_psi . ', a tread depth of ' . $model->tread_depth . '" and should be used on a rim width of ' . $model->rim_range . '".';




        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        if ($model->save()) {
            $this->data['id'] = $model->id;
            $this->trxnCompleted();
            return $this->setResponse();
        }


        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (ProductModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
