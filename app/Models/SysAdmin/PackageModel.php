<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel; 

class PackageModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_package';
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
        'package_name',
        'days',
        'price',
        'description', 
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'package_name',
        'days',
        'price',
        'description', 
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];
    

    function ListByStatus($status){
        $this->data = PackageModel::where("is_active",$status)->orderBy("package_name")->get()->toArray(); 
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = PackageModel::where($this->filter)->whereLike(['package_name','days', 'price', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = PackageModel::find($id)->toArray();
        return $this->setResponse();
    }



    function Store($data)
    {
        //Model Initialized 
        $model = new PackageModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'package_name.required' => 'Please enter package name',
            'package_name.unique' => 'The package name has already been taken.',
            'days.required' => 'Please enter package name',
            'days.integer' => 'The days must be integer value.',
            'price.required' => 'Please enter price',
            'price.integer' => 'The price must be integer value.',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'package_name' => 'required|max:100|unique:t_sys_package,package_name',
            'days' => 'required|integer',
            'price' => 'required|integer',
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = PackageModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['package_name'] =  $this->rules['package_name'] . ',' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->package_name    = $this->data['package_name'];
        $model->days            = $this->data['days'];
        $model->price           = $this->data['price'];
        $model->description = $this->data['description'];
      
        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        if ($model->save()) {  
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        $model = PackageModel::find($id); 
        if ($model->delete()) { 
            $this->trxnCompleted();
            return $this->setResponse();
        } 
        $this->intlSrvError();
        return $this->setResponse();
    }
}

