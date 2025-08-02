<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Shared\BaseModel;
use App\Models\SysAdmin\UserModel;
use App\Models\SysAdmin\UserRoleModel;
use App\Models\FIN\Sys\SysArInvoiceModel;
use App\Models\FIN\Sys\SysArPaymentModel;
use App\Models\SysAdmin\AuthModel;
use App\Traits\FIN\ArTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CompanyModel extends BaseModel
{
    use HasFactory, Notifiable, ArTransaction;
    protected $table = 't_crm_company';
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
        'guid',
        'id',
        'internal_name',
        'company_name',
        'company_no',
        'vat_no',
        'email',
        'contact_person',
        'contact_no',
        'logo',
        'address_first_line',
        'address_second_line',
        'city',
        'postcode',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'is_free_trail',
        'free_trail_days',
        'reg_date',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];

    protected $map = [
        'guid',
        'id',
        'internal_name',
        'company_name',
        'company_no',
        'vat_no',
        'email',
        'contact_person',
        'contact_no',
        'mobile_no',
        'logo',
        'address_first_line',
        'address_second_line',
        'city',
        'postcode',
        'is_vat_inv',
        'inv_frq',
        'no_of_br',
        'per_br_price',
        'is_free_trail',
        'free_trail_days',
        'reg_date',
        'description',
        'is_active',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by'
    ];


    public function Users()
    {
        return $this->hasMany(UserModel::class, 'company_id', 'id');
    }

    public function Invoices()
    {
        return $this->hasMany(SysArInvoiceModel::class, 'company_id', 'id');
    }
    public function Payments()
    {
        return $this->hasMany(SysArPaymentModel::class, 'company_id', 'id');
    }

    public function Branches()
    {
        return $this->hasMany(BranchModel::class, 'company_id', 'id');
    }

    function ListByStatus($status)
    {
        $this->data = CompanyModel::where("is_active", $status)->orderBy("company_name")->get()->toArray();
        return $this->setResponse();
    }

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = CompanyModel::where($this->filter)->whereLike(['company_name', 'email', 'contact_person', 'contact_no', 'address_first_line'], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = CompanyModel::where($this->filter)->whereLike(['company_name', 'email', 'contact_person', 'contact_no', 'address_first_line'], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        if (is_numeric($id)) {
            $data = CompanyModel::find($id);
        } else {
            $data = CompanyModel::where("guid", $id)->first();
        }
        if ($data != null) {
            $this->data = $data->toArray();
        }
        return $this->setResponse();
    }

    function Store($data)
    {
        try {
            //Model Initialized 
            $model = new CompanyModel;
            //Set Data Validation Error Messages
            $this->err_msgs = [
                'company_name.required' => 'Please enter name',
                'company_name.unique' => 'The name has already been taken.',
                'email.required' => 'Please enter email',
                'email.unique' => 'The email has already been taken.',
            ];
            //Set Data Validation Rules

            $this->data = $data;


            // $request->validate([
            //     'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            // ]);

            // $logoFile = time().'.'. $this->data["logoFile"]->extension();  

            // $this->data["logoFile"]->move(public_path('uploads/company/logo'), $logoFile);



            // Validate the request...
            if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
                $model = CompanyModel::find($this->data['id']);
                if ($model == null) {
                    $this->dataNotFound();
                    return $this->setResponse();
                }
                $this->rules = [
                    'company_name' => 'required|max:100|unique:t_crm_company,company_name',
                    'email' => 'required|max:100|email|unique:t_crm_company,email'
                ];
                $this->rules['company_name'] =  $this->rules['company_name'] . ',' . $model->id;
                $this->rules['email'] =  $this->rules['email'] . ',' . $model->id;

                $model->updated_by = $this->CurrentUserId();
                $model->is_active   =  $this->data['is_active'];
            } else {
                $this->rules = [
                    'company_name' => 'required|max:100|unique:t_crm_company,company_name',
                    'email' => 'required|max:100|email|unique:t_crm_company,email',
                    'email' => 'required|max:100|email|unique:t_sys_user,email',
                ];
                $model->created_by =  $this->CurrentUserId();
                $this->isNew = true;

                $company = new CompanyModel();
                $com = $company->select([DB::raw("concat('MYG',ifnull(max(REPLACE(t_crm_company.internal_name, 'MYG', ''))  + 1 , concat(DATE_FORMAT(now(), '%y'), '00001'))) AS internal_name")])
                    ->whereBetween('created_at', [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()])
                    ->first();
                $model->internal_name = $com->internal_name;
            }
            $model->company_name = $this->data['company_name'];
            $model->company_no = $this->data['company_no'];
            $model->vat_no = $this->data['vat_no'];
            $model->email = $this->data['email'];
            $model->contact_person = $this->data['contact_person'];
            $model->contact_no = $this->data['contact_no'];
            $model->mobile_no = $this->data['mobile_no'];
            $model->is_vat_inv = $this->data['is_vat_inv'];
            $model->inv_frq = $this->data['inv_frq'];
            $model->no_of_br = $this->data['no_of_br'];
            $model->per_br_price = $this->data['per_br_price'];
            $model->is_free_trail = $this->data['is_free_trail'];
            $model->free_trail_days = $this->data['free_trail_days'];
            $model->reg_date = $this->data['reg_date'];
            $model->address_first_line = $this->data['address_first_line'];
            $model->address_second_line = $this->data['address_second_line'];
            $model->city = $this->data['city'];
            $model->postcode = $this->data['postcode'];
            $model->description = $this->data['description'];

            if (!$this->dataValidation()) {
                return $this->setResponse();
            }

            //Upload Logo
            if ($this->data['fileSource'] !== null) {

                $this->uploadDir  = "company/logo/";
                $this->fileSource = $this->data['fileSource'] !== null ? $this->data['fileSource'] : '';
                $this->oldFile =  $model->logo !== null ? $model->logo : '';;
                $model->logo  = $this->base64ImageUpload();
            }
            //Upload Logo 

            DB::beginTransaction();
            if ($model->save()) {

                $this->data["id"] = $model->id;
                //Create new user for company
                if ($this->isNew) {



                    $user_type = "company admin";
                    $password = Str::random(12);
                    $hashed_password = Hash::make($password);

                    $userVal = [
                        'fullname' => $model->contact_person,
                        'email' => $model->email,
                        'password' => $hashed_password,
                        'user_type' => $user_type,
                        'created_by' => $model->created_by
                    ];
                    // dd( $userVal);            
                    $user =  new UserModel($userVal);
                    $model->Users()->save($user);
                    //Assign role
                    $userRole =  new UserRoleModel();
                    $userRole->user_id = $user->id;
                    $userRole->role_id = 2;
                    $userRole->save();
                    //Assign role
                    //Welcome Email
                    $this->templateId = "new-company";
                    $this->toName  = $model->company_name;
                    $this->toEmail = $model->email;
                    //$token = "1234";
                    $authModel =  new AuthModel();
                    $token = $authModel->generateToken($model->email);
                    //$this->additionalDetails = "<p>Username:" . $model->email . "<br/> Password:" . $password . " </p>";
                    $url = config('app.url') . '#/auth/account/password/reset/' . $this->toEmail . '/' . $token;
                    $this->arrData = array('name' => $model->contact_person, "url" => $url, "htmlView" => "emails.myg.welcome");
                    $this->mygEmail();
                    //Welcome Email
                    //Create invoice 
                    if ($model->is_free_trail === "N") {
                        //Invoice Number
                        $this->cid =  $model->id;
                        $this->getSysArInvoiceNo();
                        //Invoice Number

                        $inv_no = $this->newNum;
                        $inv_date = Carbon::now()->toDateTimeString();
                        $due_date = Carbon::now()->addDays(7);
                        // dd($due_date);
                        $tot_price = $model->no_of_br * $model->per_br_price;
                        $tot_price = $model->no_of_br * $model->per_br_price;
                        $vat_rate = 0.20;
                        $tot_vat = $tot_price  *  $vat_rate;
                        $tot_price_vat =   $tot_price + $tot_vat;
                        $invVal = [
                            'company_id' => $model->id,
                            'inv_no' => $inv_no,
                            'inv_date' => $inv_date,
                            'due_date' => $due_date,
                            'description' =>  $model->description,
                            'is_vat_inv' => $model->is_vat_inv,
                            'inv_frq' => $model->inv_frq,
                            'no_of_br' => $model->no_of_br,
                            'per_br_price' => $model->per_br_price,
                            'tot_price' => $tot_price,
                            'vat_rate' => $vat_rate,
                            'tot_vat' => $tot_vat,
                            'tot_price_vat' => $tot_price_vat,
                            'inv_status' => "Pending",
                            'created_by' => $model->created_by
                        ];

                        // dd( $userVal);            
                        $inv =  new SysArInvoiceModel($invVal);
                        $invIns = $model->Invoices()->save($inv);
                        $invObj =  new SysArInvoiceModel();
                        $invModel = $invObj->Get($invIns->id);

                        //invoice Email
                        $this->templateId = "myg-invoice";
                        $this->arrData =  $invModel->data;
                        $this->toName  = $model->company_name;
                        $this->toEmail = $model->email;
                        $this->mygInvoiceEmail();
                        //invoice Email 
                    }
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
            dd($e->getMessage());
            return $this->setResponse();
        }
    }



    function Remove($id) //DELETE
    {
        $model = CompanyModel::find($id);
        if ($model) {
            $branches = $model->Branches;
            if (!$branches) {
                $this->trxnNotCompleted();
                $this->message = "You are prohibited from deleting this company as it has branches existing in the system.";
                return $this->setResponse();
            }
            if ($model->delete()) {
                $model->Users()->delete($id);
                $model->Invoices()->delete($id);
                $model->Payments()->delete($id);
                $this->delTrxnCompleted();
                return $this->setResponse();
            }
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
