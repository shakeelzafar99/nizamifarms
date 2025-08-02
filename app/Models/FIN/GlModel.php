<?php
namespace App\Models\FIN; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable; 
use App\Models\Shared\BaseModel;  

class GlModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_fin_gl';
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
        'gl_code',
        'parent_id',
        'start_date',
        'end_date',
        'gl_balance',
        'balance_type',
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
        'gl_code',
        'parent_id',
        'start_date',
        'end_date',
        'gl_balance',
        'balance_type',
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
        $this->data = GlModel::where($this->filter)->whereLike(['gl_balance', 'balance_type', 'gl_type_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    } 

    function Get($id) //Single  
    {  
        $this->data = GlModel::find($id)->toArray(); 
        return $this->setResponse();
    }

    function Store($data)
    { 
        //Model Initialized 
        $model = new GlModel;  
        //Set Data Validation Error Messages
        $this->err_msgs = [
                'gl_type_name.required' => 'Please enter name',
                'gl_type_name.unique' => 'The name has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            'gl_type_name' => 'required|unique:t_fin_gl|max:50',
            'gl_balance' => 'required'
        ];
        $this->data = $data;
        
        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) { 
            $model = GlModel::find($this->data['id']); 
            if($model == null){
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['gl_type_name'] =  $this->rules['gl_type_name'] . ',gl_type_name,' . $model->id;
            $model->updated_by = $this->CurrentUserId();      
            $model->is_active   =  $this->data['is_active'];         
        }else{
            $model->created_by =  $this->CurrentUserId();     
        }  
        $model->gl_code = $this->data['gl_code'];
        $model->gl_type_name = $this->data['gl_type_name'];
        $model->parent_id = $this->data['parent_id'];
        $model->start_date = $this->data['start_date'];
        $model->end_date = $this->data['end_date'];
        $model->gl_balance = $this->data['gl_balance'];
        $model->balance_type = $this->data['balance_type'];
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
        if(GlModel::find($id)->delete()){
            $this->trxnCompleted(); 
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse(); 
    }   
}
