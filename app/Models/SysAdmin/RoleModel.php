<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Arr;

class RoleModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_role';
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
        'type',
        'urole_name',
        'description',
        'is_default',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $map = [
        'id',
        'company_id',
        'type',
        'urole_name',
        'description',
        'is_default',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];



    public function RoleManus()
    {
        return $this->hasMany(RoleMenuModel::class, 'role_id', 'id');
    }


    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $company_id =  $this->currentUser()->company_id;
            $this->filter = Arr::add($this->filter, 'company_id', $company_id);
            $this->data = RoleModel::where($this->filter)->whereLike(['urole_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = RoleModel::where($this->filter)->whereLike(['urole_name', 'description'], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = RoleModel::find($id)->toArray();
        return $this->setResponse();
    }



    function Store($data)
    {
        //Model Initialized 
        $model = new RoleModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'urole_name.required' => 'Please enter Role Name',
            'urole_name.unique' => 'The Role Name has already been taken.',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'urole_name' => 'required|max:50|unique:t_sys_role,urole_name'
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = RoleModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $this->rules['urole_name'] =  $this->rules['urole_name'] . ',' . $model->id;
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }

        $model->company_id =  $this->currentUser()->company_id;
        $model->urole_name  = $this->data['urole_name'];
        $model->type = $this->data['type'];
        $model->description = $this->data['description'];

        if (!$this->dataValidation()) {
            return $this->setResponse();
        } 
        if ($model->save()) {
            $roleMenus = $this->roleMenuIds($this->data);
            if (isset($roleMenus) && count($roleMenus) > 0) {
                $model->RoleManus()->delete();
                $roleMenusModel = [];
                foreach ($roleMenus as $key => $menu_id) {
                    $menu =  new RoleMenuModel(['menu_id' => $menu_id]);
                    $roleMenusModel[] = $menu;
                }
                $model->RoleManus()->saveMany($roleMenusModel);
            }

            $this->trxnCompleted();
            return $this->setResponse();
        }

        $this->intlSrvError();
        return $this->setResponse();
    }

    function roleMenuIds($data)
    {
        $ids = [];
        // Recursively check for children and extract their ids
        if (isset($data['role_menus'])) {
            foreach ($data['role_menus'] as $menu) {
                $ids[] = $menu["id"];  // Merge ids from nested role_menus
                if (isset($menu['children'])) {
                    foreach ($menu['children'] as $child) {
                        $ids[] = $child["id"];  // Merge ids from nested children
                        if (isset($child['children'])) {
                            foreach ($child['children'] as $subChild) {
                                $ids[] = $subChild["id"];  // Merge ids from nested children
                            }
                        }
                    }
                }
            }
        }
        sort($ids);
        return $ids;
    }

    function Remove($id) //DELETE
    {
        $model = RoleModel::find($id);

        if ($model->delete()) {
            $model->RoleManus()->delete();
            $this->trxnCompleted();
            return $this->setResponse();
        }

        $this->intlSrvError();
        return $this->setResponse();
    }
}
