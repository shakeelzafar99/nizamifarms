<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;

class RoleMenuModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_role_menu';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
        'id',
        'role_id',
        'menu_id'
    ];

    protected $map = [
        'id',
        'role_id',
        'menu_id'
    ];

   
    function List($data) //All record
    {
        $this->listRequest($data);
        $this->data = RoleMenuModel::where($this->filter)->whereLike(['urole_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = RoleMenuModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new RoleMenuModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'urole_name.required' => 'Please enter Role Name',
            'urole_name.unique' => 'The Role Name has already been taken.',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'urole_name' => 'required|unique:t_sys_role|max:50'
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = RoleMenuModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['urole_name'] =  $this->rules['urole_name'] . ',urole_name,' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->urole_name  = $this->data['urole_name'];
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
        if (RoleMenuModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
