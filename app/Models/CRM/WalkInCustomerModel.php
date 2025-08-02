<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\Common;

class WalkInCustomerModel extends BaseModel
{
    use HasFactory, Notifiable, Common;
    protected $table = 't_crm_walk_in_customer';
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
        'reg_no',
        'full_name',
        'contact_no',
        'email',
        'is_active',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'company_id',
        'reg_no',
        'full_name',
        'contact_no',
        'email',
        'is_active',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function Autocomplete($val, $val1)
    {
        $this->keyword = strtoupper($val);
        try {
            $company_id =  $this->CurrentUser()->company_id;
            $this->data =  WalkInCustomerModel::select('id', 'cust_no', 'full_name', 'contact_no', 'email', 'postcode', 'description')->where("company_id", $company_id)->where("is_active", "Y")->whereRaw('UPPER(`name`) like ?', ['%' . $this->keyword . '%'])->orderBy("name")->get()->toArray();
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = WalkInCustomerModel::where($this->filter)->whereLike([
            'company_id',
            'cust_no',
            'full_name',
            'contact_no',
            'email',
            'address_first_line',
            'postcode',
        ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {

        $this->data = WalkInCustomerModel::find($id)->toArray();
        return $this->setResponse();
    }

    function GetByRegNo($regNo, $company_id = 0 ) //Single  
    {
        if($company_id == 0){
            $company_id =  $this->CurrentUser()->company_id;
        }        
        $this->data = WalkInCustomerModel::where("reg_no", "=", $regNo)->where("company_id", "=", $company_id)->get()->toArray();
        return $this->setResponse();
    }


    function GetCountByCompany($id) //Single  
    {
        $list = WalkInCustomerModel::where('company_id', '=', $id)->get();
        return $list->count();
    }

    function Store($data)
    {

        try {
            //Model Initialized 
            $model = new WalkInCustomerModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'name.required' => 'Please enter name',
                'email.required' => 'Please enter email',
                'email.unique' => 'The email has already been taken.',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'name' => 'required',
                'email' => 'required|max:100|email|unique:t_crm_customer,email',
            ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = WalkInCustomerModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $this->rules['email'] =  $this->rules['email'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $model->created_by =  $this->CurrentUserId();
                //Customer Number
                $this->cid =  $this->data['company_id'];
                $this->getCustNo();
                $model->cust_no = $this->newNum;
                //Customer Number 

            }

            $model->full_name = $this->data['full_name'];
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
        if (WalkInCustomerModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
