<?php
namespace App\Models\FIN; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class GlTypeModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_gl_type';
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
        'gl_type_name',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'id',
        'gl_type_name',
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
        $this->data = GlTypeModel::where($this->filter)->whereLike(['gl_type_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    } 

    function Get($id) //Single  
    {  
        $this->data = GlTypeModel::find($id)->toArray(); 
        return $this->setResponse();
    }

    function Store($data)
    { 
        //Model Initialized 
        $model = new GlTypeModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                'gl_type_name.required' => 'Please enter name',
                'gl_type_name.unique' => 'The name has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            'gl_type_name' => 'required|unique:t_fin_gl_type|max:50',
            'description' => 'required'
        ];
        $this->data = $data;
               
        // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = GlTypeModel::find($this->data['id']);  
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  
     
        $model->gl_type_name = $this->data['gl_type_name'];
        $model->description = $this->data['description'];

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
        if(GlTypeModel::find($id)->delete()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse(); 
    }   


}
