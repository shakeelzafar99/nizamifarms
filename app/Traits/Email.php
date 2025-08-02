<?php

namespace App\Traits;

use App\Mail\mygMail;
use App\Mail\mygInvoiceMail;
use App\Models\SysAdmin\EmailTemplateModel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

trait Email
{

   protected string $templateId = "";
   protected string $toEmail = "";
   protected string $toName = "";
   protected string $additionalDetails = "";
   protected array $arrData = [];

   protected EmailTemplateModel $emailTemp;

   protected function mygEmail()
   {
      try {
         $template = new EmailTemplateModel();
         $this->emailTemp = $template->GetByTemplateId($this->templateId);
         
         if ($this->emailTemp->is_active === "Y") {
            //Email Data
            //$data = array('name' => $this->toName, "msg" => $this->emailTemp->body.$this->additionalDetails);
            $this->arrData["subject"] = Str::replace('%company_name%', $this->toName,  $this->emailTemp->subject);
            $this->arrData["msg"] = $this->emailTemp->body;
            //Send Email 
            Mail::to($this->toEmail, $this->toName)->queue(new mygMail($this->arrData));
         }
      } catch (\Exception $e) {
         dd($e->getMessage());
      }
   }

   protected function mygInvoiceEmail()
   {
      try {
         $template = new EmailTemplateModel();

         $this->emailTemp = $template->GetByTemplateId($this->templateId);

         if ($this->emailTemp->is_active === "Y") {
            //Email Data
            $data = array('name' => $this->toName, "subject" => $this->emailTemp->subject, "msg" => $this->emailTemp->body, "INV" => $this->arrData);
            //Send Email 
            Mail::to($this->toEmail, $this->toName)->queue(new mygInvoiceMail($data));
         }
      } catch (\Exception $e) {
         dd($e->getMessage());
      }
   }
}
