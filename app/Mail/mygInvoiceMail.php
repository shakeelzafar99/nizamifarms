<?php

namespace App\Mail;

use App\Models\SysAdmin\ConfigModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use PDF;

class mygInvoiceMail extends Mailable implements ShouldQueue
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
        try {
            $config = new ConfigModel();
            $mygs = $config->GetById();
            $this->data['logo'] = $mygs->logo;
            $this->data['inv_logo'] = $mygs->inv_logo;
            $this->data['company_name'] = $mygs->full_name;  
            $data = array(
                'MYG' => $mygs->toArray(),
                'INV' => $this->data['INV'],
                'logo' => $mygs->logo,
                'inv_logo' => $mygs->inv_logo,
                'company_name' => $mygs->full_name
            );
            view()->share('data', $data); 
            $pdf = PDF::loadView('emails.myg.pdf.invoice', $data); 
            return $this->from($mygs->no_reply_email, $mygs->full_name)->subject($this->data['subject'].' - '.$this->data['INV']["inv_no"])->view('emails.myg.mail')
                ->with(['name' => $this->data['name'], 'msg' => $this->data['msg'], 'logo' => $mygs->logo, 'company_name' => $mygs->full_name])->attachData($pdf->output(), $this->data['INV']["inv_no"] . '.pdf');
        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }
}
