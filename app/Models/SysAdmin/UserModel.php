<?php

namespace App\Models\SysAdmin;

use App\Models\CRM\BranchModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use App\Models\Shared\BaseModel;
use Illuminate\Support\Facades\DB;
use App\Models\SysAdmin\UserRoleModel;
use App\Models\SysAdmin\AuthModel;
use App\Models\CRM\CompanyModel;

class UserModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user';
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
        'branch_id',
        'fullname',
        'email',
        'password',
        'user_type',
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
        'branch_id',
        'fullname',
        'email',
        'password',
        'user_type',
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
        if ($this->flag === "Pagination") {
            $this->data = UserModel::where($this->filter)->whereLike([
                'fullname',
                'email',
                'user_type',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = UserModel::where($this->filter)->whereLike([
                'fullname',
                'email',
                'user_type',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        //dd($id);
        $this->data = UserModel::find($id)->toArray();

        if ($this->data["company_id"] > 0) {
            $company = new CompanyModel();
            $comp = $company->Get($this->data["company_id"]);
            $this->data["CompanyModel"] = $comp->data;
        }

        if ($this->data["branch_id"] > 0) {
            $branch = new BranchModel();
            $bran = $branch->Get($this->data["branch_id"]);
            $this->data["BranchModel"] = $bran->data;
        }
        return $this->setResponse();
    }

    function Store($data)
    {
        try {

            //Model Initialized 
            $model = new UserModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'email.required' => 'Please enter email',
                'email.unique' => 'The email has already been taken.',
            ];
            //Set Data Validation Rules
            $this->rules = [
                'email' => 'required|max:100|email|unique:t_sys_user,email',

            ];

            $this->data = $data;
            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = UserModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $this->rules['email'] =  $this->rules['email'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $model->created_by =  $this->CurrentUserId();
                $model->is_pwd_reset = "N";
                $this->isNew = true;
            }
            $model->company_id = $this->data['company_id'];
            $model->branch_id = $this->data['branch_id'];
            $model->fullname = $this->data['fullname'];
            $model->email = $this->data['email'];
            $model->password =  Hash::make($this->data['password']);
            $model->user_type = $this->data['user_type'];
            $model->description = $this->data['description'];


            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            DB::beginTransaction();
            if ($model->save()) {
                $this->data["id"] = $model->id;
                //Create new user for company
                if ($this->isNew &&  $model->user_type == "branch user") {

                    //Assign role
                    $userRole =  new UserRoleModel();
                    $userRole->user_id = $model->id;
                    $userRole->role_id = 3;
                    $userRole->save();
                    //Assign role 

                    //Welcome Email
                    $this->templateId = "new-user";
                    $this->toName  = $model->fullname;
                    $this->toEmail = $model->email;
                    $authModel =  new AuthModel();
                    $token = $authModel->generateToken($model->email);
                    $url = config('app.url') . '#/auth/account/password/reset/' . $this->toEmail . '/' . $token;
                    $this->arrData = array('name' => $model->fullname, "url" => $url, "htmlView" => "emails.myg.welcome");
                    $this->mygEmail();
                    //Welcome Email 

                }
                //End Create new user for company
                DB::commit();
                $this->trxnCompleted();
                return $this->setResponse();
            }
            DB::rollBack();
            $this->intlSrvError();
            return $this->setResponse();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->intlSrvError();
            $this->errors[0] = $e->getMessage(); 
            return $this->setResponse();
        }
    }


    public function change_password($data)
    {
        //Model Initialized 
        $model = new UserModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'old_password.required' => 'Please enter description',
            // 'old_password.unique' => 'The description has already been taken.',                
        ];
        //Set Data Validation Rules
        $this->rules = [
            'old_password' => 'required|unique:t_sys_user|max:200',

        ];
        $this->data = $data;

        // Validate the request...

        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = UserModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            } else {

                $this->rules['old_password'] =  $this->rules['old_password'] . ',' . $model->id;
                $model->updated_by = $this->CurrentUserId();
                if ((Hash::check($this->data['old_password'], $model->password)) == false) {
                    dd("Check your old password.");
                    $arr = array("status" => 400, "message" => "Check your old password.", "data" => array());
                } else if ((Hash::check($this->data['new_password'], $model->password)) == true) {
                    dd("Please enter a password which is not similar then current password.");
                    $arr = array("status" => 400, "message" => "Please enter a password which is not similar then current password.", "data" => array());
                } else {
                    $model->password = Hash::make($this->data['new_password']);
                    $arr = array("status" => 200, "message" => "Password updated successfully.", "data" => array());
                }
            }
        }

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
        if (UserModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
