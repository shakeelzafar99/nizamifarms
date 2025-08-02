<?php

namespace App\Models\PDM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Str;

class ServiceModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_pdm_service';
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
        'service_name',
        'description',
        'is_validity_req',
        'validity_period',
        'is_vat_cal',
        'cost_value',
        'sale_value',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'service_name',
        'description',
        'is_validity_req',
        'validity_period',
        'is_vat_cal',
        'cost_value',
        'sale_value',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function Autocomplete($company_id, $branch_id, $value, $value1, $value2)
    {

        try {

            $this->keyword =  Str::of($value)->trim()->upper();

            $whereData = [
                ['is_active', 'Y'],
                ['company_id', $this->currentUser()->company_id],
                ['service_name', 'like', '%' . $this->keyword . '%']
            ];

            $this->data =  ServiceModel::select('id', 'service_name', 'is_validity_req', 'validity_period', 'is_vat_cal','cost_value', 'sale_value')->where($whereData)->orderBy("service_name")->get()->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = ServiceModel::where($this->filter)->whereLike(['service_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = ServiceModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new ServiceModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'company_id.required' => 'Please enter company',
            'service_name.required' => 'Please enter service name',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'company_id' => 'required',
            'service_name' => 'required|max:50',

        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = ServiceModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }
        $model->company_id = $this->data['company_id'];
        $model->service_name = $this->data['service_name'];
        $model->is_validity_req = $this->data['is_validity_req'];
        $model->validity_period = $this->data['validity_period'];
        $model->is_vat_cal = $this->data['is_vat_cal'];
        $model->cost_value = $this->data['cost_value'];
        $model->sale_value = $this->data['sale_value'];
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
        if (ServiceModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
