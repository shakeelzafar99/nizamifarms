<?php
namespace App\Models\SysAdmin; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use Illuminate\Support\Facades\Hash;
use App\Models\Shared\BaseModel;  

class EmailTemplateModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_email_template';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true; 

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'template_id',
        'type',
        'created_at',
        'created_by',
        'description',
        'subject',
        'id',
        'is_active',
        'body',
        'updated_at',
        'updated_by',

    ];

    protected $map = [
        'template_id',
        'type',
        'created_at',
        'created_by',
        'description',
        'subject',
        'id',
        'is_active',
        'body',
        'updated_at',
        'updated_by',
    ];
   
    function List($data) //All record
    {  
        $this->listRequest($data); 
        if($this->flag === "Pagination"){
            $this->data = EmailTemplateModel::where($this->filter)->whereLike([
                'user_name',
                'email',
                'user_type',], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        }else{
            $this->data["data"] = EmailTemplateModel::where($this->filter)->whereLike([
                'user_name',
                'email',
                'user_type',], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }        
        return $this->setResponse();
    } 

    function Get($id) //Single  
    { 
        $this->data = EmailTemplateModel::find($id)->toArray(); 
        return $this->setResponse();
    }


    function GetByTemplateId($templateId) //Single  
    { 
        return EmailTemplateModel::where('template_id', $templateId)->first(); 
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new EmailTemplateModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                // 'email.required' => 'Please enter email',
                // 'email.unique' => 'The email has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            //'email' => 'required|unique:t_sys_user|max:50',
            
        ];
        $this->data = $data;
           
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = EmailTemplateModel::find($this->data['id']); 
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
           // $this->rules['email'] =  $this->rules['email'] . ',email,' . $model->id;
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  
        $model->template_id = $this->data['template_id'];
        $model->type = $this->data['type'];
        $model->subject = $this->data['subject'];
        $model->body = $this->data['body'];  

        if(!$this->dataValidation()){
            return $this->setResponse();
        } 

        if($model->save()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    } 

    function Remove($id) //DELETE
    {  
        if(EmailTemplateModel::find($id)->delete()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse(); 
    }   
  
}
