<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Carbon\Carbon;

class EnquiryModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_enquiry';
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
        'name',
        'email',
        'subject',
        'description',
        'contact_no',
        'datetime',
        'created_at',
        'updated_at',

    ];

    protected $map = [
        'id',
        'name',
        'email',
        'subject',
        'description',
        'contact_no',
        'datetime',
        'created_at',
        'updated_at',
    ];

    function List($data) //All record
    {
        $this->listRequest($data);
        if ($this->flag === "Pagination") {
            $this->data = EnquiryModel::where($this->filter)->whereLike([
                'name',
                'email',
                'subject',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->paginate($this->pageSize)->toArray();
        } else {
            $this->data["data"] = EnquiryModel::where($this->filter)->whereLike([
                'name',
                'email',
                'subject',
            ], $this->searchTerm)->orderBy($this->column, $this->direction)->get()->toArray();
        }
        return $this->setResponse();
    }

    function Get($id) //Single  
    {
        $this->data = EnquiryModel::find($id)->toArray();
        return $this->setResponse();
    }

    function Store($data)
    {
        //Model Initialized 
        $model = new EnquiryModel;
        //Set Data Validation Error Messages
        $this->err_msgs = [
            'email.required' => 'Please enter email',
        ];
        //Set Data Validation Rules
        $this->rules = [
            'email' => 'required|max:50',

        ];
        $this->data = $data;

        // Validate the request...
        if (array_key_exists("id", $this->data) && $this->data['id'] > 0) {
            $model = EnquiryModel::find($this->data['id']);
            if ($model == null) {
                $this->dataNotFound();
                return $this->setResponse();
            }
        }
        $model->name = $this->data['name'];
        $model->contact_no = $this->data['contact_no'];
        $model->email = $this->data['email'];
        $model->subject = $this->data['subject'];
        $model->description = $this->data['description'];
        $model->datetime = Carbon::now();

        if (!$this->dataValidation()) {
            return $this->setResponse();
        } 

        if ($model->save()) {

             //Contact Us 
             $this->templateId = "contact-us";
             $this->toName  = $model->name;
             $this->toEmail = $model->email;
             $this->mygEmail();
             //Contact Us

            $this->trxnCompleted();
            return $this->setResponse();
        } 
        $this->intlSrvError();
        return $this->setResponse();
    }

    function Remove($id) //DELETE
    {
        if (EnquiryModel::find($id)->delete()) {
            $this->trxnCompleted();
            return $this->setResponse();
        }
        $this->intlSrvError();
        return $this->setResponse();
    }
}
