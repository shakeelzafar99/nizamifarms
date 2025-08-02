<?php
namespace App\Models\HR; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class DesignationModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_hr_designation';
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
        'designation_name',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'designation_name',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    function List($data) //All record
    {  
        $this->listRequest($data);         
        $this->data = DesignationModel::where($this->filter)->whereLike(['designation_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    } 

    function Get($id) //Single  
    {  
        $this->data = DesignationModel::find($id)->toArray(); 
        return $this->setResponse();
    }

    function Store($data)
    { 
        //Model Initialized 
        $model = new DesignationModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                'designation_name.required' => 'Please enter name',
                'designation_name.unique' => 'The name has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            'designation_name' => 'required|unique:t_hr_designation|max:50',
            'description' => 'required'
        ];
        $this->data = $data;
                
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = DesignationModel::find($this->data['id']); 
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
            //$this->rules['gl_type_name'] =  $this->rules['gl_type_name'] . ',gl_type_name,' . $model->id;
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  

        $model->designation_name = $this->data['designation_name'];
        $model->description = $this->data['description'];      
        // $model->logo = $this->data['logo'];   
        
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
        if(DesignationModel::find($id)->delete()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse(); 
    }   
}
