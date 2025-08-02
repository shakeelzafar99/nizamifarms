<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\PackageModel;
use Carbon\Carbon;

class BranchModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_crm_branch';
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
        'branch_name',
        'email',
        'contact_person',
        'contact_no',
        'mobile_no',
        'address',
        'logo',
        'short_code',
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
        'branch_name',
        'email',
        'contact_person',
        'contact_no',
        'address',
        'logo',
        'short_code',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    function ListByStatus($status)
    {
        $this->data = BranchModel::where("is_active", $status)->orderBy("branch_name")->get()->toArray();
        return $this->setResponse();
    }
    function List($data) //All record
    {
     
        $this->listRequest($data);
        if ($this->flag === "Pagination") { 
         
            $this->data = BranchModel::where($this->filter)->whereLike([
                'company_id',
                'branch_name',
                'email',
                'contact_person',
                'contact_no',
                'address_first_line',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
       
        } else {
            $this->data["data"] = BranchModel::where($this->filter)->whereLike([
                'company_id',
                'branch_name',
                'email',
                'contact_person',
                'contact_no',
                'address_first_line',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }


    function Filter($data) //All record
    {  
        
        $this->listRequest($data);   
        $this->data = BranchModel::where($this->filter)->orderBy("branch_name", "asc")->get()->toArray();
        return $this->setResponse();
    } 

    function Get($id) //Single  
    {
        $this->data = BranchModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        try {
            //Model Initialized 
            $model = new BranchModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'branch_name.required' => 'Please enter email',
                // 'email.unique' => 'The email has already been taken.',
            ];
            //Set Data Validation Rules
            // $this->rules = [
            //     'email' => 'required|max:100|email|unique:t_crm_branch,email',  
            // ];
            $this->data = $data;
            $model->company_id = $this->data['company_id'];
            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = BranchModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                //$this->rules['email'] =  $this->rules['email'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                //Allowed Branches Validations
                $company = new CompanyModel();
                $comp = $company->Get($model->company_id);
                $no_of_br = $comp->data["no_of_br"];
                $brList = BranchModel::where('company_id', '=', $model->company_id)->get();
                $brCount = $brList->count();
                if ($brCount >= $no_of_br) {
                    $this->resetResponse();
                    $this->data = [];
                    $this->message = "Input data validation error";
                    $this->errors[] = "You are allowed total " . $no_of_br . " branches, please contact system administrator for further information";
                    $this->status = "Error";
                    return $this->setResponse();
                }
                //Allowed Branches Validations
                $model->created_by =  $this->CurrentUserId();
            } 
            $model->branch_name = $this->data['branch_name'];
            // $model->email = $this->data['email'];
            $model->contact_person = $this->data['contact_person'];
            $model->contact_no = $this->data['contact_no'];
            $model->mobile_no = $this->data['mobile_no']; 
            $model->address_first_line = $this->data['address_first_line'];
            $model->address_second_line = $this->data['address_second_line'];
            $model->city = $this->data['city'];
            $model->postcode = $this->data['postcode'];
            $model->description = $this->data['description'];
            //$model->logo = $this->data['logo'];
            //  $model->short_code = $this->data['short_code'];


            if (!$this->dataValidation()) {
                return $this->setResponse();
            }



            //Upload Logo
            // if ($this->data['fileSource'] !== null) {

            //     $this->uploadDir  = "branch/logo/";
            //     $this->fileSource = $this->data['fileSource'] !== null ? $this->data['fileSource'] : '';
            //     $this->oldFile =  $model->logo !== null ? $model->logo : '';;
            //     $model->logo  = $this->base64ImageUpload();
            // }
            //Upload Logo

            if ($model->save()) {
                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (\Exception $e) {
            dd($e->getMessage());
        }

        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (BranchModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
