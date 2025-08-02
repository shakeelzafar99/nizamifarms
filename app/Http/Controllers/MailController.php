<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\SendMail;
use Illuminate\Support\Facades\Mail;

class MailController extends Controller
{

   public function sendEmail()
   {
      try {
         $data = array('name' => "Mail Controller");

         Mail::send('emails.test', $data, function ($message) {
            $message->to('shakeel.zafar@gmail.com', 'Tutorials Point')->subject('Laravel Basic Testing Mail');
            //$message->from('shakeel.zafar@compilesol.com','Compilesol');
         });
         echo "Basic Email Sent. Check your inbox.";
      } catch (\Exception $e) {
         dd($e->getMessage());
      }
   }

   //  public function sendEmail() {


   //      $data = [
   //         'message' => 'this is for testing'

   //   ];

   //   Mail::to('shakeel.zafar@gmail.com')->send(new SendMail($data));
   //      //Mail::to("devilsam646@gmail.com")->send(new TestMail($details));
   //      return "Email Sent";
   //   }

}
