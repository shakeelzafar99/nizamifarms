<?php

namespace App\Models\SysAdmin;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel; 
use Illuminate\Support\Str; 
use Illuminate\Support\Facades\Hash;

class AuthModel extends BaseModel
{
    use HasFactory, Notifiable;
    protected $table = 't_sys_user';
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
        'email',
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
        'email',
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

    //Welcome Email New User
    //   $this->templateId = "new-user";
    //   $this->toName  = $model->company_name;
    //   $this->toEmail = $model->email;
    //   $this->additionalDetails = "<p>Username:". $model->email."<br/> Password:".$password." </p>";
    //   $this->mygEmail();
    //Welcome Email New User


    public function resetPassword($data)
    { 
        $this->data = $data;  
        $passreset = ResetPassModel::where('email', $this->data["email"])->where('token', $this->data["token"])->first(); 
        if ($passreset === null) {
            $this->dataNotFound();
            $this->message = "Password reset unsuccessful. Please verify your email and try again.";      
            return $this->setResponse(); 
        } else {
            $model = $this->validEmail($this->data["email"]); 
            $model->password =  Hash::make($this->data['password']);
            $model->is_pwd_reset = "Y"; 
            if ($model->save()) {
            // If email exists
            $this->resetPasswordSuccessMail($model);
            $this->trxnCompleted();
            $this->message = "Your password has been successfully reset. Please use your new password to log in.";
            return $this->setResponse();  
            }  
        } 
        $this->intlSrvError();
        return $this->setResponse();

    }

    public function forgotPassword($request)
    {
        
        // If email does not exist
        $user = $this->validEmail($request["email"]); 
        if ($user === null) {
            $this->dataNotFound();
            $this->message = "Data not found for the provided email address.";
            return $this->setResponse();
            // return response()->json([
            //     'message' => 'Email does not exist.'
            // ], Response::HTTP_NOT_FOUND);

        } else {
            // If email exists
            $this->sendMail($user);
            $this->trxnCompleted();
            $this->message = "A password reset link has been sent to your email. Please check your inbox.";
            return $this->setResponse();           
        }
    }
    public function resetPasswordSuccessMail($model)
    {
        //Info Email
        $this->templateId = "reset-password-success";
        $this->toName  = $model->fullname;
        $this->toEmail = $model->email;
        $this->arrData = array('name' => $model->fullname, "htmlView" => "emails.myg.mail");
        $this->mygEmail();
        //Info Email 
    }
    public function sendMail($model)
    {
        $token = $this->generateToken($model->email);
        //Welcome Email
        $this->templateId = "reset-password";
        $this->toName  = $model->fullname;
        $this->toEmail = $model->email; 
        $url = config('app.url') . '#/auth/account/password/reset/'.$this->toEmail.'/' . $token; 
        $this->arrData = array('name' => $model->fullname, "url" => $url, "htmlView" => "emails.myg.resetPassword"); 
        //$this->additionalDetails = "<p>Username:" . $model->email . "<br/> Password:" . $password . " </p>";
        $this->mygEmail();
        //Welcome Email

        //Mail::to($email)->send(new SendMail($token));
    }
    public function validEmail($email)
    {  
          $user = AuthModel::whereRaw('LOWER(trim(email)) = ?', [strtolower(trim($email))])->first(); 
          return $user;
    }
    public function generateToken($email)
    {
        $isOtherToken = ResetPassModel::where('email', $email)->first();
        if ($isOtherToken) {
            return $isOtherToken->token;
        }
        $token = Str::slug(Str::random(40));
        $this->storeToken($token, $email);
        return $token;
    }
    public function storeToken($token, $email)
    {
        $model = new ResetPassModel;
        $model->token = $token;
        $model->email = $email;
        $model->save(); 
    } 
}
