<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;

class CompanyConfigModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_crm_company_config';
    protected $primaryKey = 'id';
    protected $isNew = false;
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
        'short_name',
        'full_name',
        'email',
        'no_reply_email',
        'logo',
        'slogo',
        'inv_logo',
        'vat_no',
        'company_no',
        'contact_no',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $map = [
        'id',
        'company_id',
        'short_name',
        'full_name',
        'email',
        'no_reply_email',
        'logo',
        'slogo',
        'inv_logo',
        'vat_no',
        'company_no',
        'contact_no',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];


  
    function ListByStatus($status)
    {
        $this->data = CompanyConfigModel::where("is_active", $status)->orderBy("short_name")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = CompanyConfigModel::where($this->filter)->whereLike(['short_name',
            'full_name',
            'email',], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = CompanyConfigModel::where($this->filter)->whereLike(['short_name',
            'full_name',
            'email',], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $get = CompanyConfigModel::where("company_id","=",$id)->get();
        if($get){
            $this->data = $get->toArray();
        }
        return $this->setResponse();
    }
 
    function Get0($id) //Single  
    {
        try { 
            $this->data = CompanyConfigModel::find($id)->toArray(); 
        } catch (\Exception $e) {
            dd($e->getMessage());
            return $this->error($e->getMessage(), $e->getCode());
        }
        dd($this->data);
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new CompanyConfigModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                'email.required' => 'Please enter email',
                            
        ];
        //Set Data Validation Rules
        $this->rules = [
            'email' => 'required|max:50',
            
        ];
        $this->data = $data;
           
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = CompanyConfigModel::find($this->data['id']); 
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['email'] =  $this->rules['email'] . ',email,' . $model->id;
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  
        $model->company_id = $this->data['company_id'];
        $model->short_name = $this->data['short_name'];
        $model->full_name = $this->data['full_name']; 
        $model->email = $this->data['email'];
        $model->no_reply_email = $this->data['no_reply_email'];        
        $model->logo = $this->data['logo'];
        $model->slogo = $this->data['slogo']; 
        $model->inv_logo = $this->data['inv_logo'];
        $model->vat_no = $this->data['vat_no'];
        $model->company_no = $this->data['company_no'];
        $model->contact_no = $this->data['contact_no']; 

        if(!$this->dataValidation()){
            return $this->setResponse();
        } 

        //Upload Logo
        $this->uploadDir  = "company/logo/";

        if ($this->data['logoSource'] !== null) {
            $this->fileSource = $this->data['logoSource'] !== null ? $this->data['logoSource'] : ''; 
            $this->oldFile =  $model->logo !== null ? $model->logo : ''; 
            $model->logo      = $this->base64ImageUpload();
        }

        if ($this->data['slogoSource'] !== null) {
            $this->fileSource = $this->data['slogoSource'] !== null ? $this->data['slogoSource'] : '';
            $this->oldFile =  $model->slogo !== null ? $model->slogo : ''; 
            $model->slogo     = $this->base64ImageUpload();
        }

        if ($this->data['inv_logoSource'] !== null) { 
            $this->fileSource = $this->data['inv_logoSource'] !== null ? $this->data['inv_logoSource'] : '';
            $this->oldFile =  $model->inv_logo !== null ? $model->inv_logo : ''; 
            $model->inv_logo  = $this->base64ImageUpload();
        } 

        //Upload Logo
        
        if($model->save()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    } 

    function Remove($id) //DELETE
    {
        if (CompanyConfigModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
