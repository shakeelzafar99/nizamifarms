<?php

namespace App\Models\SCM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class SupplierModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_scm_supplier';
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
        'supplier_name', 
        'contact_person',
        'contact_no',
        'email',
        'address_first_line',
        'address_second_line',
        'city',
        'postcode', 
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'supplier_name',
        'contact_person',
        'contact_no',
        'email',
        'address_first_line',
        'address_second_line',
        'city',
        'postcode', 
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    function ListByStatus($status)
    {
        $this->data = SupplierModel::where("is_active", $status)->orderBy("supplier_name")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = SupplierModel::where($this->filter)->whereLike(['supplier_name', 'description',], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = SupplierModel::where($this->filter)->whereLike(['supplier_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = SupplierModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {

        try {
            //Model Initialized 
            $model = new SupplierModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'supplier_name.required' => 'Please enter supplier name',
                //'supplier_name.unique' => 'The description has already been taken.',
            ];
            //Set Data Validation Rules
            $this->rules = [
                //'supplier_name' => 'required|unique:t_scm_supplier|max:50',
                'supplier_name' => 'required'
            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = SupplierModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                //$this->rules['supplier_name'] =  $this->rules['supplier_name'] . ',supplier_name,' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $model->created_by =  $this->CurrentUserId();
            }

            $model->supplier_name = $this->data['supplier_name']; 
            $model->contact_person = $this->data['contact_person'];
            $model->contact_no = $this->data['contact_no'];
            $model->email = $this->data['email'];
            $model->address_first_line = $this->data['address_first_line'];
            $model->address_second_line = $this->data['address_second_line'];
            $model->city = $this->data['city'];
            $model->postcode = $this->data['postcode'];
            $model->description = $this->data['description'];
            $model->company_id = $this->data['company_id'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            if ($model->save()) {
                $this->trxnCompleted();
                return $this->setResponse();
            }
            $this->intlSrvError();
            return $this->setResponse();
            
        } catch (\Exception $e) {
            $this->intlSrvError();
            $this->errors[0] = $e->getMessage();
            return $this->setResponse();
        }
    }

    function Remove($id) //DELETE
    {
        if (SupplierModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
