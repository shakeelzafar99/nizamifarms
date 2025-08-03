<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Traits\Common;
use DB;

class CustomerModel extends BaseModel
{
    use HasFactory, Notifiable, Common;
    protected $table = 't_crm_customer';
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
        'shopify_id',
        'email',
        'created_at',
        'updated_at',
        'first_name',
        'last_name',
        'state',
        'note',
        'verified_email',
        'updated_by',
        'tax_exempt',
        'phone',
        'created_by'
    ];

    protected $map = [
        'id',
        'shopify_id',
        'email',
        'created_at',
        'updated_at',
        'first_name',
        'last_name',
        'state',
        'note',
        'verified_email',
        'updated_by',
        'tax_exempt',
        'phone',
        'created_by'
    ];

    function Autocomplete($company_id, $branch_id, $value, $value1, $value2)
    {
        $this->keyword = strtoupper($value);
        try {
            //DB::enableQueryLog(); // Enable query log
            $this->data =  CustomerModel::select('id', 'cust_no', 'company_name', 'contact_no', 'email', 'postcode', 'description')->where("company_id", $company_id)->where("is_active", "Y")->whereRaw('UPPER(`company_name`) like ?', ['%' . $this->keyword . '%'])->orderBy("company_name")->get()->toArray();
            //dd(DB::getQueryLog());
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = CustomerModel::where($this->filter)->whereLike([
            'company_id',
            'cust_no',
            'company_name',
            'contact_no',
            'email',
            'address_first_line',
            'postcode',
        ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = CustomerModel::find($id)->toArray();
        return $this->setResponse();
    }
 

    function Store($data)
    {

        try {
            //Model Initialized 
            $model = new CustomerModel;
            // //Set Data Validation Error Messages
            // $this->err_msgs = [
            //     'company_name.required' => 'Please enter company name',
            //     'email.required' => 'Please enter email',
            //     'email.unique' => 'The email has already been taken.',
            // ];
            // //Set Data Validation Rules
            // $this->rules = [
            //     'company_name' => 'required',
            //     'email' => 'required|max:100|email|unique:t_crm_account_customer,email',
            // ];
            $this->data = $data;

            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = CustomerModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $this->rules['email'] =  $this->rules['email'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId(); 
            } else {
                $model->created_by =  $this->CurrentUserId();  
            }

            $model->shopify_id = $this->data['shopify_id'];
            $model->email = $this->data['email'];
            $model->first_name = $this->data['first_name'];
            $model->last_name = $this->data['last_name'];
            $model->state = $this->data['state'];
            $model->note = $this->data['note'];
            $model->verified_email = $this->data['verified_email'];
            $model->note = $this->data['note']; 

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
        if (CustomerModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
