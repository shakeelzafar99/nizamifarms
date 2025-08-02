<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class BrandModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_brand';
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
        'name',
        'description',
        'logo',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'brand_name',
        'description',
        'logo',
        'is_active',
        'entryon',
        'entryby',
        'updatedon',
        'updatedby',
    ];


    public function product()
    {
        return $this->hasMany(ProductModel::class, 'brand_id');
    }

    function ListByStatus($status)
    {
        $this->data = BrandModel::where("is_active", $status)->orderBy("brand_name")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = BrandModel::where($this->filter)->whereLike(['brand_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = BrandModel::where($this->filter)->whereLike(['brand_name',  'description'], $this->searchTerm)->orderBy("brand_name", "asc")->get()->toArray();
        }
        return $this->setResponse();


        // $this->listRequest($data);         
        // $this->data = BrandModel::where($this->filter)->whereLike(['brand_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        // return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = BrandModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new BrandModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'brand_name.required' => 'Please enter name',
            'brand_name.unique' => 'The name has already been taken.',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'brand_name' => 'required|max:50|unique:t_pdm_brand,brand_name',
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = BrandModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['brand_name'] =  $this->rules['brand_name'] . ',' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->brand_name = $this->data['brand_name'];
        $model->description = $this->data['description'];
        $model->logo = $this->data['logo'];


        if (!$this->dataValidation()) {
            return $this->setResponse();
        }


        //Upload Logo
        $this->uploadDir  = "brands/logo/";

        if ($this->data['logoSource'] !== null) {
            $this->fileSource = $this->data['logoSource'] !== null ? $this->data['logoSource'] : '';
            $this->oldFile =  $model->logo !== null ? $model->logo : '';
            $model->logo      = $this->base64ImageUpload();
        }
        //Upload Logo 
        if ($model->save()) { 
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
    
        $model = BrandModel::find($id);
        if ($model) {
            $this->oldFile =  $model->logo !== null ? $model->logo : '';
            if ($model->delete()) {
                $this->removeUploadedImage();
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
