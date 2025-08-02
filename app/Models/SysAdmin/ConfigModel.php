<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class ConfigModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_config';
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
        'updated_at',
        'updated_by',

    ];

    protected $map = [
        'id',
        'short_name',
        'full_name',
        'email',
        'no_reply_email',
        'logo',
        'slogo',
        'inv_logo',
        'vat_no',
        'company_no',
        'updated_at',
        'updated_by',
    ];

    function Get($id) //Single  
    {
        $this->data = ConfigModel::find($id)->toArray();
        //$path = env('APP_STORAGE_URL').$this->data["inv_logo"]; 
        $path = public_path("\storage\\" . $this->data["inv_logo"]);
        if (file_exists($path)) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $this->data["enc_logo"] = "data:image/" . $ext . ";base64," . base64_encode(file_get_contents($path));
        }
        return $this->setResponse();
    }


    function GetById() //Single  
    {
        $model = ConfigModel::where('id', 1)->first();

        return $model;
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new ConfigModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'short_name.required' => 'Please enter shot name',

        ];
        //Set Data Validation Rules
        $this->rules = [
            'short_name' => 'required',

        ];
        $this->data = $data;

        // Validate the request...
        if (!array_key_exists("id", $this->data) && $this->data['id'] <= 0) {
            $this->data['id'] = 1;
        }

        $model = ConfigModel::find($this->data['id']);

        if ($model == null) {
            $this->dataNotFound();
            return $this->setResponse();
        }

        $model->id = $this->data['id'];
        $model->short_name = $this->data['short_name'];
        $model->full_name = $this->data['full_name'];
        $model->email = $this->data['email'];
        $model->no_reply_email = $this->data['no_reply_email'];
        $model->vat_no = $this->data['vat_no'];
        $model->company_no = $this->data['company_no'];
        $model->contact_no = $this->data['contact_no'];
        $model->updated_by = $this->CurrentUserId();

        if (!$this->dataValidation()) {
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

        if ($model->save()) {
            $this->data["id"] = $model->id;
            $this->trxnCompleted();
            return $this->setResponse();
        }

        $this->intlSrvError();
        return $this->setResponse();
    }
}
