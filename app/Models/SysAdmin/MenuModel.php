<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;

class MenuModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_menu';
    protected $primaryKey = 'id';
    // Rest omitted for brevity 
    public $timestamps = true;
    protected   $roleId = 0;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'title',
        'parent_id',
        'page',
        'icon',
        'sort',
        'is_active',
        'is_sys_nav',
        'is_user_nav',
        'is_grid_nav',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];



    protected $map = [
        'id',
        'title',
        'parent_id',
        'page',
        'icon',
        'sort',
        'is_active',
        'is_sys_nav',
        'is_user_nav',
        'is_grid_nav',
        'description',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];





    function List($data) //All record
    {
        try {
            $this->listRequest($data);
            if ($this->flag === "Pagination") {
                $this->data = MenuModel::where($this->filter)
                    ->whereLike(['title', 'page'], $this->searchTerm)
                    ->where("parent_id", 0)
                    ->orderBy($this->column, $this->direction)
                    ->with('children') // eager load the child relationship
                    ->paginate($this->pageSize)
                    ->toArray();
                //$this->data = MenuModel::where($this->filter)->whereLike(['title', 'page'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
            } else {
                $this->data["data"] = MenuModel::where($this->filter)->whereLike(['title', 'page'], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
            }
            return $this->setResponse();
        } catch (\Exception $e) {
            $this->intlSrvError();
            $this->errors[0] = $e->getMessage();
            return $this->setResponse();
        }
    }


    function Get($id) //Single  
    {
        $this->data = MenuModel::find($id)->toArray();
        return $this->setResponse();
    }

    public function submenu()
    {
        $this->roleId  =  (int)session('transId');
        if ($this->roleId > 0) {
            return $this->hasMany(MenuModel::class, 'parent_id', 'id')->where("is_active", '=', 'Y')->whereIn('id', function ($query) {
                $query->select('menu_id')
                    ->from(with(new RoleMenuModel())->getTable())
                    ->where('role_id', '=',  $this->roleId);
            })->orderBy('sort');
        } else {
            return $this->hasMany(MenuModel::class, 'parent_id', 'id')->where("is_active", '=', 'Y')->whereIn('id', function ($query) {
                $query->select('menu_id')
                    ->from(with(new RoleMenuModel())->getTable())
                    ->whereIn('role_id',  function ($query) {
                        $query->select('role_id')
                            ->from(with(new UserRoleModel())->getTable())
                            ->where("user_id", $this->CurrentUserId());
                    });
            })->orderBy('sort');
        }
    }

    function Navtree()
    {
        $menus = DB::table('t_sys_menu as m')
            ->join('t_sys_role_menu as rm', 'm.id', '=', 'rm.menu_id')
            ->join('t_sys_user_role as ur', 'ur.role_id', '=', 'rm.role_id')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('m.is_active', 'Y')
            ->where('m.is_nav_bar', 'Y')
            ->where('r.is_active', 'Y')
            ->where('ur.user_id', $this->CurrentUserId())
            ->select([
                'm.id',
                'm.title',
                'm.parent_id',
                'm.page',
                'm.icon',
                'm.sort',
                'm.is_active',
                'm.is_sys_nav',
                'm.is_user_nav',
                'm.is_grid_nav',
                'm.is_nav_bar',
                'm.description'
            ])
            ->distinct()
            ->get();

        // Convert to Parent-Child Nested Array
        $menuMap = [];
        $nestedArray = [];

        // First, create a mapping of menus by ID
        foreach ($menus as $menu) {
            $menuArray = (array) $menu;
            $menuArray['children'] = []; // Initialize children array
            $menuMap[$menu->id] = $menuArray;
        }

        // Build the parent-child hierarchy
        foreach ($menus as $menu) {
            if ($menu->parent_id == 0) {
                // Top-level menu
                $nestedArray[] = &$menuMap[$menu->id];
            } else {
                // Child menu
                if (isset($menuMap[$menu->parent_id])) {
                    $menuMap[$menu->parent_id]['children'][] = &$menuMap[$menu->id];
                }
            }
        }
        $this->data = array('items' =>  $nestedArray);
        return $this->setResponse();
    }
    function Navtree0($roleId)
    {
        $this->roleId = $roleId;
        session(['transId' => $this->roleId]);
        if ($this->roleId > 0) {
            $userMenu = static::with(implode('.', array_fill(0, 100, 'submenu')))->where('parent_id', '=', '0')->whereIn('id', function ($query) {
                $query->select('menu_id')
                    ->from(with(new RoleMenuModel())->getTable())
                    ->where('role_id', $this->roleId);
            })->where("is_active", '=', 'Y')->orderBy('sort')->get()->toArray();
        } else {
            $userMenu = static::with(implode('.', array_fill(0, 100, 'submenu')))->where('parent_id', '=', '0')->whereIn('id', function ($query) {
                $query->select('menu_id')
                    ->from(with(new RoleMenuModel())->getTable())
                    ->whereIn('role_id',  function ($query) {
                        $query->select('role_id')
                            ->from(with(new UserRoleModel())->getTable())
                            ->where("user_id", $this->CurrentUserId());
                    });
            })->where("is_active", '=', 'Y')->orderBy('sort')->get()->toArray();
        }
        $this->data = array('items' =>  $userMenu);
        return $this->setResponse();
    }
    function Permission($request)
    {
        $userId = $this->CurrentUserId();
        $page = $request->page;
        // Fetch parent menu item based on the page and user role
        $this->data = MenuModel::where('t_sys_menu.is_active', 'Y')
            ->where('t_sys_menu.page', $page)
            ->join('t_sys_role_menu', 't_sys_menu.id', '=', 't_sys_role_menu.menu_id')
            ->join('t_sys_user_role', 't_sys_role_menu.role_id', '=', 't_sys_user_role.role_id')
            ->where('t_sys_user_role.user_id', $userId)
            ->select('t_sys_menu.*')
            ->get()
            ->toArray(); // Convert to array

        if ($this->data) {
            // Fetch children if the parent exists
            $this->data[0]["children"] = MenuModel::where('parent_id', $this->data[0]["id"])
                ->where('t_sys_menu.is_active', 'Y')
                ->join('t_sys_role_menu', 't_sys_menu.id', '=', 't_sys_role_menu.menu_id')
                ->join('t_sys_user_role', 't_sys_role_menu.role_id', '=', 't_sys_user_role.role_id')
                ->where('t_sys_user_role.user_id', $userId)
                ->select('t_sys_menu.*')
                ->get()
                ->toArray(); // Convert to array
        }
        try {

            // Log activity
            ActivityModel::create([
                'session_id' => $request->bearerToken(),
                'user_id'    => $userId,
                'page'       => $page,
                'menu_id'    => $this->data[0]["id"] ?? null, // Avoids undefined property error
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Catch the exception and handle it
            // Optionally, log the error for debugging
            dd('Error storing activity: ' . $e->getMessage());

            // Return a custom error response
            return response()->json(['error' => 'An error occurred while logging activity.'], 500);
        }
        return $this->setResponse();
    }



    function Permission0($request)
    {
        $this->data = MenuModel::where('t_sys_menu.is_active', 'Y')
            ->where('t_sys_menu.page', '=', $request->page)
            ->join('t_sys_role_menu', 't_sys_menu.id', '=', 't_sys_role_menu.menu_id')
            ->join('t_sys_user_role', 't_sys_role_menu.role_id', '=', 't_sys_user_role.role_id')
            ->where('t_sys_user_role.user_id', $this->CurrentUserId())
            ->select('t_sys_menu.*') // Select only the menu columns
            ->get()
            ->toArray();
        if ($this->data) {
            $this->data[0]["children"] = MenuModel::where('parent_id', $this->data[0]["id"])
                ->where('t_sys_menu.is_active', 'Y')
                ->join('t_sys_role_menu', 't_sys_menu.id', '=', 't_sys_role_menu.menu_id')
                ->join('t_sys_user_role', 't_sys_role_menu.role_id', '=', 't_sys_user_role.role_id')
                ->where('t_sys_user_role.user_id', $this->CurrentUserId())
                ->select('t_sys_menu.*') // Select only the menu columns
                ->get()
                ->toArray();
        }


        $activityModel = new ActivityModel();
        $activityModel->session_id = $request->bearerToken();
        $activityModel->user_id = $this->CurrentUserId();
        $activityModel->page =  $request->page;
        $activityModel->created_at =  now();

        if ($this->data) {
            $activityModel->menu_id =  $this->data[0]["id"];
        }
        $activityModel->save();

        return $this->setResponse();
    }



    public function children()
    {
        return $this->hasMany(MenuModel::class, 'parent_id', 'id')
            ->where('is_active', 'Y')  // Ensure that the child menus are active
            ->orderBy('sort')  // Sort by the menu sorting order
            ->with('children');  // Load children recursively
    }


    public function Tree($roleId)
    {
        $this->roleId = $roleId;

        $this->data = DB::table('t_sys_menu as m')
            ->leftJoin('t_sys_role_menu as rm', function ($join) {
                $join->on('m.id', '=', 'rm.menu_id')
                    ->where('rm.role_id', '=', $this->roleId);
            })
            ->where('m.is_active', 'Y')
            ->orderBy('m.sort')
            ->select('m.id', 'm.title', 'm.page', 'm.parent_id', 'm.description', 'rm.id as role_menu_id')
            ->get()->toArray();


        // Initialize an array to hold parent-child nested structure
        $nestedArray = [];

        // Create a map for quick lookup of menus by ID
        $menuMap = [];

        // Populate the map with menu items
        foreach ($this->data as $menu) {
            $menuArray = (array) $menu;
            // Set isChecked based on role_menu_id
            $menuArray['isChecked'] = !is_null($menu->role_menu_id);
            $menuMap[$menu->id] = $menuArray;
        }

        // Now, build the nested array
        foreach ($this->data as $menu) {
            // Check if the menu has a parent, if not, it's a top-level menu
            if ($menu->parent_id == 0) {
                $nestedArray[] = &$menuMap[$menu->id];
            } else {
                // Otherwise, assign the menu as a child to the parent
                $parent = &$menuMap[$menu->parent_id];
                if (!isset($parent['children'])) {
                    $parent['children'] = [];
                }
                $parent['children'][] = &$menuMap[$menu->id];
            }
        }

        // Now $nestedArray contains the parent-child structure
        $this->data =  $nestedArray;
        return $this->setResponse();
    }


    function Store($data)
    {
        //Model Initialized 
        $model = new MenuModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'parent_id.required' => 'Please enter parent id',
            'parent_id.integer' => 'Parent must be a number',
            'title.required' => 'Please enter title',
            'title.string' => 'This title must be text',
            'sort.required' => 'Please enter parent id',
            'sort.integer' => 'This sort must be a number',

        ];

        //Set Data Validation Rules
        $this->rules = [
            'parent_id' => 'required|integer',
            'title' => 'required|string',
            'sort' => 'required|integer',
        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = MenuModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
            $model->updated_by = $this->CurrentUserId();
            $model->is_active   =  $this->data['is_active'];
        } else {
            $model->created_by =  $this->CurrentUserId();
        }
        $model->title = $this->data['title'];
        $model->parent_id = $this->data['parent_id'];
        $model->page = $this->data['page'];
        $model->icon = $this->data['icon'];
        $model->sort = $this->data['sort'];
        $model->is_sys_nav = $this->data['is_sys_nav'];
        $model->is_user_nav = $this->data['is_user_nav'];
        $model->is_grid_nav = $this->data['is_grid_nav'];
        $model->description = $this->data['description'];

        if (!$this->dataValidation()) {
            return $this->setResponse();
        }

        try {
            if ($model->save()) {

                $this->trxnCompleted();
                return $this->setResponse();
            }
        } catch (\Exception $e) {
            $this->intlSrvError();
            $this->errors[0] = $e->getMessage();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }


    function Remove($id) //DELETE
    {
        if (MenuModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
