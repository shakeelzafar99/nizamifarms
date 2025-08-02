<?php

namespace App\Mail;

use App\Models\SysAdmin\ConfigModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class mygMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels; 
    public $data;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Build the message.
     *
     * @return $this
     */

    public function build()
    {      
        $config = new ConfigModel();
        $mygs = $config->GetById();
        $this->data['logo'] = $mygs->logo;
        $this->data['company_name'] = $mygs->full_name;   
        return $this->from($mygs->no_reply_email,$mygs->full_name)->subject($this->data['subject'])->view($this->data['htmlView']) 
                    ->with([ 'data' => $this->data]);
    }
}
